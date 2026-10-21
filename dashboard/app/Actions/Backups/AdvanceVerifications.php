<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\VerificationReason;
use App\Enums\Backups\VerificationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\WalArchive;
use App\Models\ServiceInstance;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Scheduled restore verification (docs/14 §10.4): every service whose
 * backups are scheduled (or that has point-in-time recovery) is verified
 * every `verification.every_days` (7) days, at a minute derived from its id
 * so they spread out, once it has a successful backup. A verification that
 * failed to start is not retried before the next interval; "Verify now"
 * always works.
 */
final readonly class AdvanceVerifications
{
    public function __construct(
        private StartVerification $start,
    ) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now()->utc();
        $every = config()->integer('launchroom.backups.verification.every_days');

        $ids = BackupSchedule::query()->where('enabled', true)->whereNotNull('service_instance_id')->pluck('service_instance_id')
            ->merge(WalArchive::query()->where('enabled', true)->pluck('service_instance_id'))->unique()->values()->all();

        if ($ids === []) {
            return 0;
        }

        $verified = BackupVerification::query()->whereIn('service_instance_id', $ids)
            ->where('created_at', '>', $now->subDays($every))->pluck('service_instance_id')->flip();
        $backedUp = BackupRun::query()->whereIn('service_instance_id', $ids)
            ->where('status', BackupRunStatus::Succeeded->value)->distinct()->pluck('service_instance_id')->flip();

        $started = 0;

        foreach (ServiceInstance::query()->whereIn('id', $ids)->whereNull('removed_at')->orderBy('id')->get() as $instance) {
            if ($verified->has($instance->id) || ! $backedUp->has($instance->id) || crc32($instance->public_id.'|verify') % 60 !== $now->minute) {
                continue;
            }

            try {
                $verification = $this->start->handle($instance, VerificationReason::Scheduled, null, AuditActor::system());
                $started += $verification->status === VerificationStatus::Pending ? 1 : 0;
            } catch (DomainRuleViolation $e) {
                // Recorded so the tab says why, and not retried every minute;
                // servers whose launchroomd can't verify yet are just skipped.
                if ($e->field !== 'daemon') {
                    $this->recordSkipped($instance, $e->getMessage());
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $started;
    }

    private function recordSkipped(ServiceInstance $instance, string $reason): void
    {
        $run = BackupRun::query()->where('service_instance_id', $instance->id)->where('status', BackupRunStatus::Succeeded->value)->orderByDesc('id')->first();

        if (! $run instanceof BackupRun || $run->snapshot_id === null) {
            return;
        }

        $verification = BackupVerification::query()->create([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'backup_run_id' => $run->id,
            'destination_id' => $run->destination_id,
            'subject' => $run->subject,
            'volume_id' => $run->volume_id,
            'snapshot_id' => $run->snapshot_id,
            'snapshot_taken_at' => $run->finished_at,
            'reason' => VerificationReason::Scheduled,
        ]);
        $verification->transitionTo(VerificationStatus::Failed, $reason);
    }
}
