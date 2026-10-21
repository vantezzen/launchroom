<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Models\Registry\RegistryStorage;
use JsonException;

/**
 * Renders Zot's config.json (docs/05 §6, docs/14 §9.11). Local storage
 * renders the same document as the installer's template
 * (deploy/images/zot/config.json with the dashboard URL as token realm; a
 * test keeps the two in sync); S3 storage swaps in Zot's `s3` storage driver.
 *
 * With S3, `dedupe` is off: Zot's S3 dedupe stores duplicate blobs as
 * placeholders resolved through a cache database, which (without DynamoDB or
 * Redis) is a local file on the control-plane disk, so losing the server
 * would leave every deduplicated blob unresolvable. Off, each repository
 * holds its own copy of shared layers in the bucket: more storage, but the
 * bucket alone is a complete registry.
 *
 * The rendered document contains the S3 secret key: write it only to the
 * configured file (0600) and never log it; {@see redacted()} is for display.
 */
final readonly class ZotConfig
{
    public const string REDACTED = '[redacted]';

    public function __construct(
        private RegistryStorageSecrets $secrets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(?RegistryStorage $storage = null, bool $reveal = true): array
    {
        $storageConfig = [
            'rootDirectory' => '/var/lib/registry',
            'dedupe' => true,
            'gc' => true,
            'gcDelay' => '1h',
            'gcInterval' => '6h',
            'retention' => $this->retention(),
        ];

        if ($storage instanceof RegistryStorage) {
            $credentials = $reveal ? $this->secrets->reveal($storage) : ['accessKeyId' => self::REDACTED, 'secretAccessKey' => self::REDACTED];
            $prefix = trim($storage->prefix, '/');

            $storageConfig = [
                'rootDirectory' => '/var/lib/registry',
                'dedupe' => false,
                'remoteCache' => false,
                'gc' => true,
                'gcDelay' => '1h',
                'gcInterval' => '6h',
                'retention' => $this->retention(),
                'storageDriver' => [
                    'name' => 's3',
                    'rootdirectory' => '/'.$prefix,
                    'region' => $storage->region ?? 'us-east-1',
                    'regionendpoint' => $storage->endpoint,
                    'bucket' => $storage->bucket,
                    'forcepathstyle' => $storage->force_path_style,
                    'secure' => str_starts_with($storage->endpoint, 'https://'),
                    'skipverify' => false,
                    'accesskey' => $credentials['accessKeyId'],
                    'secretkey' => $credentials['secretAccessKey'],
                ],
            ];
        }

        $sync = [
            'enable' => true,
            'registries' => [
                $this->mirror('https://registry-1.docker.io', '/mirror/docker.io'),
                $this->mirror('https://ghcr.io', '/mirror/ghcr.io'),
            ],
        ];

        if ($storage instanceof RegistryStorage) {
            // Zot requires a local staging directory when on-demand mirroring
            // writes to remote storage: images are pulled there, then copied
            // into the bucket.
            $sync['downloadDir'] = '/var/lib/registry/.sync';
        }

        return [
            'distSpecVersion' => '1.1.1',
            'storage' => $storageConfig,
            'http' => [
                'address' => '0.0.0.0',
                'port' => '5000',
                'compat' => ['docker2s2'],
                'auth' => [
                    'bearer' => [
                        'realm' => rtrim(config()->string('app.url'), '/').'/registry/token',
                        'service' => config()->string('launchroom.registry.service'),
                        'cert' => '/etc/zot/keys/registry-token.pub.pem',
                    ],
                ],
            ],
            'log' => ['level' => 'info'],
            'extensions' => [
                'sync' => $sync,
            ],
        ];
    }

    /**
     * The file contents: pretty JSON with a trailing newline.
     *
     * @throws JsonException
     */
    public function json(?RegistryStorage $storage = null): string
    {
        return json_encode($this->render($storage), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The same document with the S3 credentials replaced, for display.
     *
     * @throws JsonException
     */
    public function redacted(?RegistryStorage $storage = null): string
    {
        return json_encode($this->render($storage, reveal: false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function retention(): array
    {
        return [
            'dryRun' => false,
            'delay' => '24h',
            'policies' => [
                [
                    'repositories' => ['apps/**'],
                    'deleteReferrers' => true,
                    'deleteUntagged' => true,
                    'keepTags' => [['mostRecentlyPushedCount' => 10]],
                ],
                [
                    'repositories' => ['cache/**'],
                    'deleteReferrers' => true,
                    'deleteUntagged' => true,
                    'keepTags' => [['mostRecentlyPushedCount' => 2]],
                ],
                [
                    'repositories' => ['mirror/**'],
                    'deleteReferrers' => false,
                    'deleteUntagged' => true,
                    'keepTags' => [['pulledWithin' => '720h']],
                    'keepUntagged' => ['pulledWithin' => '720h'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mirror(string $url, string $destination): array
    {
        return [
            'urls' => [$url],
            'onDemand' => true,
            'tlsVerify' => true,
            'maxRetries' => 3,
            'retryDelay' => '5s',
            'content' => [['prefix' => '**', 'destination' => $destination]],
        ];
    }
}
