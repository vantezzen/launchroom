<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ingress\CertStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Keeps the `lr-caddy` Valkey ACL user of the automatic shared certificate
 * storage in place (docs/05 §5): Valkey keeps ACL users in memory only, so a
 * restarted Valkey gets it back within a minute.
 */
#[Signature('launchroom:ingress:cert-storage-user')]
#[Description('Create or refresh the Valkey ACL user of the automatic shared certificate storage (docs/05 §5)')]
final class EnsureCertStorageUserCommand extends Command
{
    public function handle(CertStorage $storage): int
    {
        if ($storage->automatic() === null) {
            $this->info('Automatic certificate storage is not in use.');

            return self::SUCCESS;
        }

        $storage->ensureUser();
        $this->info('Certificate storage user is in place.');

        return self::SUCCESS;
    }
}
