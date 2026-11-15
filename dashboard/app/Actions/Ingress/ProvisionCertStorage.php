<?php

declare(strict_types=1);

namespace App\Actions\Ingress;

use App\Enums\IngressCredentialPurpose;
use App\Events\NodeStateInvalidated;
use App\Models\Server;
use App\Services\Ingress\CertStorage;
use App\Services\Ingress\EdgeIngress;
use App\Services\Ingress\EdgeServers;
use App\Services\Ingress\IngressSecrets;
use Illuminate\Support\Facades\Cache;

/**
 * The one writer of the shared certificate storage's state (docs/05 §5): the
 * storage encryption key and, for the automatic storage, the `lr-caddy`
 * password (created once) and its Valkey ACL user. Valkey keeps ACL users in
 * memory only, so this runs every minute from the scheduler
 * (`launchroom:ingress:cert-storage-user`) and, throttled to once a minute,
 * before every queued render (PrepareNodeStateRender): a restarted Valkey
 * heals within a minute and new edges find the user in place. Renders only
 * read what this provisioned; credentials created here re-render the shared
 * edges so they pick the storage up.
 */
final readonly class ProvisionCertStorage
{
    private const string THROTTLE_KEY = 'launchroom:cert-storage-user';

    public function __construct(
        private CertStorage $storage,
        private EdgeIngress $ingress,
        private IngressSecrets $secrets,
        private EdgeServers $edges,
    ) {}

    public function handle(bool $throttle = false): void
    {
        if (! $this->ingress->modulesAvailable()) {
            return;
        }

        $automatic = $this->storage->automatic();
        $configured = config('launchroom.ingress.cert_storage.address');

        if ($automatic === null && (! is_string($configured) || $configured === '')) {
            return;
        }

        $created = $this->ensure(IngressCredentialPurpose::StorageKey, EdgeIngress::generateStorageKey(...));

        if ($automatic !== null) {
            $created = $this->ensure(IngressCredentialPurpose::StoragePassword, CertStorage::generatePassword(...)) || $created;
            $password = $this->secrets->get(IngressCredentialPurpose::StoragePassword);

            if ($password !== null && (! $throttle || Cache::add(self::THROTTLE_KEY, true, 60))) {
                $this->storage->ensureUser($password);
            }
        }

        if ($created) {
            NodeStateInvalidated::servers(array_map(static fn (Server $server): int => $server->id, $this->edges->shared()));
        }
    }

    /**
     * @param  callable(): string  $generate
     * @return bool whether it was created now
     */
    private function ensure(IngressCredentialPurpose $purpose, callable $generate): bool
    {
        if ($this->secrets->get($purpose) !== null) {
            return false;
        }

        $this->secrets->ensure($purpose, $generate);

        return true;
    }
}
