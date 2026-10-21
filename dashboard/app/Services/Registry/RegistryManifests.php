<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Data\Registry\RegistryScope;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Is an image still in the registry? (docs/14 §5.2 step e: after a
 * control-plane restore, Zot is empty unless its storage was on S3; active
 * deployments whose image is gone are rebuilt from their recorded commit.)
 *
 * `HEAD /v2/<repository>/manifests/<digest>` on the dashboard's own view of
 * Zot (`launchroom.registry_lookup.url`) with a pull token for exactly that
 * repository. Only images of launchroom's registry (`127.0.0.1:5000/…`)
 * are checked.
 */
final readonly class RegistryManifests
{
    public function __construct(
        private HttpFactory $http,
        private RegistryTokenIssuer $tokens,
    ) {}

    /**
     * true: present, false: missing, null: not ours or unknown (registry unreachable).
     */
    public function exists(string $image): ?bool
    {
        $apps = config()->string('launchroom.images.apps_prefix');
        $prefix = strtok($apps, '/').'/';

        if (! str_starts_with($image, $prefix) || preg_match('#^([a-z0-9][a-z0-9._/-]{0,250})@(sha256:[a-f0-9]{64})$#D', substr($image, strlen($prefix)), $matches) !== 1) {
            return null;
        }

        [, $repository, $digest] = $matches;
        $url = rtrim(config()->string('launchroom.registry_lookup.url'), '/')."/v2/{$repository}/manifests/{$digest}";

        try {
            $token = $this->tokens->issue('dashboard', [new RegistryScope($repository, [RegistryScope::PULL])], 120);
            $response = $this->http
                ->timeout(config()->integer('launchroom.registry_lookup.timeout_seconds'))
                ->withoutRedirecting()
                ->withToken($token->token)
                ->accept('application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json, application/vnd.docker.distribution.manifest.list.v2+json')
                ->head($url);
        } catch (Throwable) {
            return null;
        }

        return match (true) {
            $response->successful() => true,
            $response->status() === 404 => false,
            default => null,
        };
    }
}
