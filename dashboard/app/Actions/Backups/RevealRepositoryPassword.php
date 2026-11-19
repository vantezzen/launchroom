<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DecryptionFailed;
use App\Exceptions\DomainRuleViolation;
use App\Models\AuditEvent;
use App\Models\Backups\BackupDestination;
use App\Services\Backups\DestinationSecrets;
use Illuminate\Support\Facades\DB;

/**
 * A destination's restic repository password, for reading its backups with
 * plain restic when launchroom itself is gone (docs/14 §3). Works for
 * removed storage too, which keeps the password. Every reveal is audited;
 * the caller authorizes (BackupDestinationPolicy::revealRepositoryPassword).
 */
final readonly class RevealRepositoryPassword
{
    public function __construct(
        private DestinationSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation when launchroom no longer has the password
     */
    public function handle(BackupDestination $destination, AuditActor $actor): string
    {
        try {
            $password = $this->secrets->repositoryPassword($destination);
        } catch (DecryptionFailed) {
            throw DomainRuleViolation::conflict('launchroom no longer has the repository password of this storage.');
        }

        DB::transaction(fn (): AuditEvent => $this->audit->handle(new AuditEntryData(
            action: AuditAction::BackupRepositoryPasswordRevealed,
            actor: $actor,
            teamId: $destination->team_id,
            subjectType: 'backup_destination',
            subjectId: $destination->public_id,
            meta: ['scope' => $destination->team_id === null ? 'instance' : 'team', 'removed' => $destination->trashed()],
        )));

        return $password;
    }
}
