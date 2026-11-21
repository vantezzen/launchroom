<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\AppUpdates\AppUpdate;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an update while it still backs up (docs/04 §14.2): nothing has
 * changed yet, and the backups that already started finish as ordinary
 * backups. Once it deploys, the deployment's own Cancel applies.
 */
final readonly class CancelAppUpdate
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AppUpdate $update, AuditActor $actor): AppUpdate
    {
        return DB::transaction(function () use ($update, $actor): AppUpdate {
            $update = AppUpdate::query()->whereKey($update->id)->with('serviceInstance.service')->lockForUpdate()->firstOrFail();

            if (! $update->status->canTransitionTo(AppUpdateStatus::Cancelled)) {
                throw DomainRuleViolation::on('update', 'The update is already deploying. Cancel its deployment instead.');
            }

            $update->transitionTo(AppUpdateStatus::Cancelled, "Cancelled before anything changed. {$update->from_version} keeps running.");

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AppUpdateCancelled,
                actor: $actor,
                teamId: $update->team_id,
                subjectType: 'service',
                subjectId: $update->serviceInstance->service->public_id,
                meta: ['update' => $update->public_id],
            ));

            return $update;
        });
    }
}
