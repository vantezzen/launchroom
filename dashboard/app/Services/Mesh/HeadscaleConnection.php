<?php

declare(strict_types=1);

namespace App\Services\Mesh;

use App\Enums\Mesh\MeshCredentialPurpose;
use App\Models\Mesh\MeshCredential;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * A HeadscaleClient authenticated with the dashboard's own API key.
 *
 * The first call bootstraps it: the installer (or `make up-multi`) leaves an
 * API key in `launchroom.mesh.bootstrap_key_path` when Headscale first
 * starts (only `headscale apikeys create` inside its container can mint the
 * first one). The dashboard uses it once to create its own key, stores that
 * envelope-encrypted, expires the bootstrap key in Headscale and deletes the
 * file when it can. Keys are rotated when they have less than
 * `api_key_rotate_days` left ({@see rotate()}).
 */
final readonly class HeadscaleConnection
{
    public function __construct(
        private MeshSecrets $secrets,
    ) {}

    public function client(): HeadscaleClient
    {
        $url = config('launchroom.mesh.headscale_url');

        if (! is_string($url) || $url === '') {
            throw HeadscaleUnavailable::because('connect', 'this install has no built-in Headscale');
        }

        $key = $this->secrets->get(MeshSecrets::instanceScope(), MeshCredentialPurpose::HeadscaleApiKey);

        if ($key !== null) {
            return new HeadscaleClient($url, $key);
        }

        return $this->bootstrap($url);
    }

    /**
     * Replaces the dashboard's API key when it expires soon. Returns whether it did.
     */
    public function rotate(): bool
    {
        $credential = $this->secrets->find(MeshSecrets::instanceScope(), MeshCredentialPurpose::HeadscaleApiKey);

        if (! $credential instanceof MeshCredential || $credential->expires_at === null
            || $credential->expires_at->isAfter(now()->addDays(config()->integer('launchroom.mesh.api_key_rotate_days')))) {
            return false;
        }

        $client = $this->client();
        $old = $this->secrets->reveal($credential);
        $this->store($client->createApiKey(now()->addDays(config()->integer('launchroom.mesh.api_key_ttl_days'))));
        $this->expire($old);

        return true;
    }

    private function bootstrap(string $url): HeadscaleClient
    {
        $path = config()->string('launchroom.mesh.bootstrap_key_path');
        $bootstrap = File::isReadable($path) ? trim(File::get($path)) : '';

        if ($bootstrap === '') {
            throw HeadscaleUnavailable::because('connect', 'no API key yet (the installer creates one when Headscale first starts)');
        }

        $client = new HeadscaleClient($url, $bootstrap);
        $key = $client->createApiKey(now()->addDays(config()->integer('launchroom.mesh.api_key_ttl_days')));
        $this->store($key);
        $this->expire($bootstrap);

        if (! File::delete($path)) {
            // Read-only mount (queue workers): harmless, the key is expired.
            Log::info('The expired Headscale bootstrap key file could not be deleted.', ['path' => $path]);
        }

        return new HeadscaleClient($url, $key);
    }

    private function store(string $key): void
    {
        $this->secrets->put(
            MeshSecrets::instanceScope(),
            MeshCredentialPurpose::HeadscaleApiKey,
            $key,
            HeadscaleClient::apiKeyPrefix($key),
            now()->addDays(config()->integer('launchroom.mesh.api_key_ttl_days')),
        );
    }

    private function expire(string $oldKey): void
    {
        $prefix = HeadscaleClient::apiKeyPrefix($oldKey);

        if ($prefix === null) {
            return;
        }

        try {
            $this->client()->expireApiKey($prefix);
        } catch (HeadscaleUnavailable $e) {
            Log::warning('The previous Headscale API key could not be expired.', ['error' => $e->getMessage()]);
        }
    }
}
