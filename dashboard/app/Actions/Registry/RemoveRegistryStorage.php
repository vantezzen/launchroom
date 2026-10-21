<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Registry\RegistryStorage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Back to local registry storage (docs/14 §9.11). The bucket and its images
 * are left alone; after `./launchroom registry apply` Zot starts with the
 * (empty or older) local storage and images are rebuilt or re-mirrored.
 */
final readonly class RemoveRegistryStorage
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, AuditActor $actor): void
    {
        if (! $user->is_instance_owner) {
            throw DomainRuleViolation::forbidden('Only an instance owner can change where the registry stores images.');
        }

        DB::transaction(function () use ($actor): void {
            $storage = RegistryStorage::query()->whereKey(RegistryStorage::ID)->lockForUpdate()->first();

            if (! $storage instanceof RegistryStorage) {
                return;
            }

            $storage->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RegistryStorageRemoved,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'registry',
                meta: ['endpoint' => $storage->endpoint, 'bucket' => $storage->bucket],
            ));
        });
    }
}
