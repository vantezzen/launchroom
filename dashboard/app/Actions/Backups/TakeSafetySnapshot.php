<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use App\Models\User;

/**
 * Automatic safety snapshot before a risky operation on a database
 * (docs/14 §3): deleting a database service, restoring into it, an engine
 * upgrade, a production deploy with a release command (opt-in). Other code
 * calls this; it never blocks the operation it protects:
 *
 *     $run = resolve(TakeSafetySnapshot::class)->handle($instance, BackupReason::PreDelete, $user, $actor);
 *     // null: not a database, no destination configured, or not running.
 *
 * Callers that must wait for the snapshot (restore-into) watch the run's
 * status. The control-plane equivalent is {@see StartControlPlaneBackup}
 * with BackupReason::PreUpgrade (`php artisan launchroom:backups:control-plane
 * --reason=pre_upgrade --wait` for the upgrade flow).
 *
 * There is no local fallback destination: without a team destination
 * the snapshot is skipped (the UI already nags until one exists).
 */
final readonly class TakeSafetySnapshot
{
    public function __construct(
        private StartDatabaseBackup $start,
    ) {}

    public function handle(ServiceInstance $instance, BackupReason $reason, ?User $user, AuditActor $actor): ?BackupRun
    {
        $instance->loadMissing('service');

        if ($instance->service->kind !== ServiceKind::Database || ! BackupDestination::defaultFor($instance->team_id) instanceof BackupDestination) {
            return null;
        }

        try {
            return $this->start->handle($instance, $reason, $user, $actor);
        } catch (DomainRuleViolation) {
            // Not running (nothing to lose) or no usable destination.
            return null;
        }
    }
}
