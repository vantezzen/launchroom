<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Registry\RegistryStorageInput;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Registry\RegistryStorage;
use App\Models\User;
use App\Services\Backups\EndpointGuard;
use App\Services\Registry\RegistryStorageSecrets;
use Illuminate\Support\Facades\DB;

/**
 * Puts (or keeps) the registry's storage on an S3-compatible bucket
 * (docs/14 §9.11). Instance owners only (the caller authorizes
 * `manageInstance`). The endpoint is
 * checked like a backup destination's (docs/09 R5): https and public
 * addresses, unless the audited private-network override is set; the
 * credentials are envelope-encrypted. Nothing reaches Zot until the config
 * is written and Zot restarted (`./launchroom registry apply`).
 */
final readonly class ConfigureRegistryStorage
{
    public function __construct(
        private EndpointGuard $guard,
        private RegistryStorageSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(RegistryStorageInput $input, User $user, AuditActor $actor): RegistryStorage
    {
        if (! $user->is_instance_owner) {
            throw DomainRuleViolation::forbidden('Only an instance owner can change where the registry stores images.');
        }

        $endpoint = $this->guard->check($input->endpoint, $input->allowPrivateNetwork);
        $region = $input->region === null || $input->region === '' ? null : strtolower($input->region);
        $prefix = trim($input->prefix ?? '', '/');
        $prefix = $prefix === '' ? 'registry' : $prefix;
        $this->guard->checkLocation($input->bucket, $prefix, $region);

        return DB::transaction(function () use ($input, $user, $actor, $endpoint, $region, $prefix): RegistryStorage {
            $storage = RegistryStorage::query()->whereKey(RegistryStorage::ID)->lockForUpdate()->first();
            $newKeys = $input->accessKeyId !== null && $input->accessKeyId !== '' && $input->secretAccessKey !== null && $input->secretAccessKey !== '';

            if (! $storage instanceof RegistryStorage && ! $newKeys) {
                throw DomainRuleViolation::on('accessKeyId', 'Enter the access key and secret of an S3 key that can read, write and delete in this bucket.');
            }

            $privateNewlyAllowed = $input->allowPrivateNetwork && (! $storage instanceof RegistryStorage || ! $storage->allow_private_network || $storage->endpoint !== $endpoint);

            $storage ??= new RegistryStorage;
            $storage->id = RegistryStorage::ID;
            $storage->fill([
                'endpoint' => $endpoint,
                'bucket' => $input->bucket,
                'region' => $region,
                'prefix' => $prefix,
                'force_path_style' => $input->forcePathStyle,
                'allow_private_network' => $input->allowPrivateNetwork,
                'updated_by' => $user->id,
            ]);

            if ($newKeys) {
                $this->secrets->seal($storage, (string) $input->accessKeyId, (string) $input->secretAccessKey);
            }

            $storage->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RegistryStorageConfigured,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'registry',
                meta: ['endpoint' => $endpoint, 'bucket' => $input->bucket, 'prefix' => $prefix, 'credentialsChanged' => $newKeys],
            ));

            if ($privateNewlyAllowed) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::RegistryStoragePrivateNetworkAllowed,
                    actor: $actor,
                    subjectType: 'instance',
                    subjectId: 'registry',
                    meta: ['endpoint' => $endpoint],
                ));
            }

            return $storage;
        });
    }
}
