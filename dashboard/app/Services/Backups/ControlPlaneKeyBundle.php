<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Models\InstanceSettings;
use App\Services\Instance\DashboardOrigin;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;
use SensitiveParameter;

/**
 * The control-plane secrets that live outside the database (docs/14 §1):
 * APP_KEY, the KEK, the signing key, the registry token key pair and the
 * `.env` values needed to start the restored stack. Without them every
 * encrypted value in the restored database is garbage and daemons won't trust
 * a new signing key.
 *
 * The plaintext JSON ("launchroom-keys/1") only ever exists in memory: it is
 * sealed to the recovery key ({@see RecoveryKey}) before restic sees it.
 *
 *   {format, createdAt, appUrl, env: {NAME: value}, files: {name: contents},
 *    headscale: {name: base64 contents}}
 *
 * `headscale` (additive, absent in older bundles) is the embedded
 * Headscale's state (docs/14 §2, docs/05 §2): its noise and DERP private
 * keys and its SQLite database (db.sqlite + db.sqlite-wal), so the mesh
 * survives a control-plane restore: same server identity, same nodes, and
 * every server's mesh client reconnects without re-registering. The files
 * are read from `launchroom.mesh.headscale_state_path` (a read-only mount of
 * Headscale's /var/lib/headscale). Headscale keeps running, so the database
 * is copied without a lock: both files are read until two consecutive reads
 * agree (size + mtime), and SQLite replays the copied WAL on open. The
 * -shm index is never copied (SQLite rebuilds it). A torn copy is possible
 * only if Headscale writes during the read and the retries run out, which
 * fails the backup instead of storing it.
 */
final class ControlPlaneKeyBundle
{
    public const string FORMAT = 'launchroom-keys/1';

    /**
     * File names inside the bundle and where `launchroom:restore` writes them
     * (relative to --keys-out; matches the installer's data/keys layout).
     */
    public const array FILES = [
        'kek' => 'kek',
        'signing.ed25519' => 'signing.ed25519',
        'registry-token.es256.pem' => 'registry-token.es256.pem',
        'registry-token.pub.pem' => 'registry-public/registry-token.pub.pem',
    ];

    /**
     * Headscale state files (inside headscale_state_path). Restored to
     * <keys-out>/headscale/<name>; the installer copies them to
     * data/headscale/lib before Headscale starts.
     */
    public const array HEADSCALE_FILES = ['noise_private.key', 'derp_server_private.key', 'db.sqlite', 'db.sqlite-wal'];

    /** Upper bound of the Headscale state in a bundle (bytes, before base64). */
    public const int HEADSCALE_MAX_BYTES = 64 * 1024 * 1024;

    /**
     * Collects the bundle from config and the key files.
     */
    public static function collect(): string
    {
        $paths = [
            'kek' => config()->string('launchroom.kek_path'),
            'signing.ed25519' => config()->string('launchroom.signing_key_path'),
            'registry-token.es256.pem' => config()->string('launchroom.registry.token_key_path'),
            'registry-token.pub.pem' => config()->string('launchroom.registry.token_public_key_path'),
        ];

        $files = [];

        foreach ($paths as $name => $path) {
            if (! is_file($path) || ! is_readable($path)) {
                if ($name === 'kek' || $name === 'signing.ed25519') {
                    throw new RuntimeException("The key file for {$name} is missing; the control plane can't be backed up without it.");
                }

                continue;
            }

            $files[$name] = (string) file_get_contents($path);
        }

        $redis = config('database.redis.default');
        $reverb = config('broadcasting.connections.reverb');

        $env = array_filter([
            'APP_KEY' => self::stringConfig('app.key'),
            'APP_PREVIOUS_KEYS' => implode(',', array_filter((array) config('app.previous_keys', []), is_string(...))),
            'DB_PASSWORD' => self::stringConfig('database.connections.pgsql.password'),
            'REDIS_PASSWORD' => is_array($redis) && is_string($redis['password'] ?? null) ? $redis['password'] : null,
            'REVERB_APP_ID' => is_array($reverb) && is_scalar($reverb['app_id'] ?? null) ? (string) $reverb['app_id'] : null,
            'REVERB_APP_KEY' => is_array($reverb) && is_string($reverb['key'] ?? null) ? $reverb['key'] : null,
            'REVERB_APP_SECRET' => is_array($reverb) && is_string($reverb['secret'] ?? null) ? $reverb['secret'] : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $headscale = self::headscale();

        return json_encode(array_filter([
            'format' => self::FORMAT,
            'createdAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            // APP_URL as installed (the restored .env's). The canonical origin
            // chosen in Settings → Dashboard domain is in the database and
            // comes back with it (docs/05 §4.1).
            'appUrl' => resolve(DashboardOrigin::class)->installUrl(),
            'env' => (object) $env,
            'files' => (object) $files,
            'headscale' => $headscale === [] ? null : (object) array_map(base64_encode(...), $headscale),
        ], static fn (mixed $value): bool => $value !== null), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Headscale's state, by file name (raw contents). Empty when no state
     * path is configured or Headscale never ran there. With the embedded mesh
     * switched on, missing keys fail the backup: a restore without them
     * would strand every server's mesh client.
     *
     * @return array<string, string>
     */
    public static function headscale(): array
    {
        $dir = config('launchroom.mesh.headscale_state_path');

        if (! is_string($dir) || $dir === '') {
            return [];
        }

        $dir = rtrim($dir, '/');
        $required = self::meshRequiresState();

        if (! is_file($dir.'/noise_private.key')) {
            if ($required) {
                throw new RuntimeException("The mesh is on but Headscale's state is not readable at {$dir}; the control plane can't be backed up without it.");
            }

            return [];
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $before = self::fingerprint($dir);
            $files = [];
            $total = 0;

            foreach (self::HEADSCALE_FILES as $name) {
                $path = $dir.'/'.$name;

                if (! is_file($path)) {
                    continue;
                }

                if (! is_readable($path)) {
                    throw new RuntimeException("Headscale's {$name} is not readable by the dashboard (see docs/14 §9.6).");
                }

                $contents = file_get_contents($path);

                if ($contents === false) {
                    throw new RuntimeException("Headscale's {$name} could not be read.");
                }

                $total += strlen($contents);

                if ($total > self::HEADSCALE_MAX_BYTES) {
                    throw new RuntimeException('Headscale\'s state is larger than '.intdiv(self::HEADSCALE_MAX_BYTES, 1024 * 1024).' MB; it is not included in key bundles.');
                }

                $files[$name] = $contents;
            }

            if ($before === self::fingerprint($dir)) {
                return $files;
            }

            Sleep::usleep(200_000);
        }

        throw new RuntimeException('Headscale kept writing its database while it was copied; try the backup again.');
    }

    private static function meshRequiresState(): bool
    {
        $url = config('launchroom.mesh.headscale_url');

        return is_string($url) && $url !== '' && InstanceSettings::current()->mesh_enabled_at !== null;
    }

    private static function fingerprint(string $dir): string
    {
        clearstatcache();
        $parts = [];

        foreach (self::HEADSCALE_FILES as $name) {
            $stat = @stat($dir.'/'.$name);
            $parts[] = $stat === false ? '-' : $stat['size'].':'.$stat['mtime'];
        }

        return implode('|', $parts);
    }

    /**
     * @return array{appUrl: string|null, env: array<string, string>, files: array<string, string>, headscale: array<string, string>}
     */
    public static function parse(#[SensitiveParameter] string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The key bundle is not valid JSON.');
        }

        if (! is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT || ! is_array($decoded['env'] ?? null) || ! is_array($decoded['files'] ?? null)) {
            throw new RuntimeException('The key bundle has an unknown format.');
        }

        $env = [];

        foreach ($decoded['env'] as $name => $value) {
            if (is_string($name) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $name) === 1 && is_string($value) && ! str_contains($value, "\n")) {
                $env[$name] = $value;
            }
        }

        $files = [];

        foreach ($decoded['files'] as $name => $contents) {
            if (is_string($name) && isset(self::FILES[$name]) && is_string($contents)) {
                $files[$name] = $contents;
            }
        }

        $headscale = [];

        foreach (is_array($decoded['headscale'] ?? null) ? $decoded['headscale'] : [] as $name => $encoded) {
            $contents = is_string($name) && in_array($name, self::HEADSCALE_FILES, true) && is_string($encoded) ? base64_decode($encoded, true) : false;

            if ($contents !== false) {
                $headscale[$name] = $contents;
            }
        }

        return ['appUrl' => is_string($decoded['appUrl'] ?? null) ? $decoded['appUrl'] : null, 'env' => $env, 'files' => $files, 'headscale' => $headscale];
    }

    private static function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }
}
