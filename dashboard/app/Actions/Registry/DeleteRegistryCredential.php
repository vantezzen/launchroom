<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Registry\RegistryCredential;
use Illuminate\Support\Facades\DB;

/**
 * Removes a team's sign-in details for a private registry. Images already
 * copied stay in launchroom's registry, so running services keep working; a
 * new deploy of an image on that host goes through the public mirror again.
 * The caller authorizes.
 */
final readonly class DeleteRegistryCredential
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(RegistryCredential $credential, AuditActor $actor): void
    {
        DB::transaction(function () use ($credential, $actor): void {
            $credential->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RegistryCredentialDeleted,
                actor: $actor,
                teamId: $credential->team_id,
                subjectType: 'registry_credential',
                subjectId: $credential->public_id,
                meta: ['host' => $credential->host, 'username' => $credential->username],
            ));
        });
    }
}
