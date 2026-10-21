<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\VolumeRetirementStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use App\Models\User;
use App\Models\VolumeRetirement;
use Illuminate\Support\Facades\DB;

/**
 * Records a person's confirmation that a retired volume whose service had
 * backups may be deleted (docs/05 §1.1). Its retention period still
 * applies; the volume sweep then requests the deletion.
 */
final readonly class ConfirmVolumeDeletion
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Server $server, string $volumeId, User $user, AuditActor $actor): void
    {
        DB::transaction(function () use ($server, $volumeId, $user, $actor): void {
            $retirement = VolumeRetirement::query()
                ->where('server_id', $server->id)
                ->where('volume_public_id', $volumeId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($retirement->status !== VolumeRetirementStatus::Retained) {
                throw DomainRuleViolation::on('volume', 'This volume’s deletion is already requested or done.');
            }

            if ($retirement->confirmed_at !== null) {
                return;
            }

            $retirement->forceFill(['confirmed_at' => now(), 'confirmed_by' => $user->id])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::VolumeDeletionConfirmed,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: ['volume' => $retirement->volume_public_id, 'label' => $retirement->label],
            ));
        });
    }
}
