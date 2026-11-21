<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Data\AppUpdates\StartAppUpdateData;
use App\Data\AppUpdates\UpdateCandidateData;
use App\Data\AppUpdates\UpdateWindowData;
use App\Data\Audit\AuditActor;
use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\AppUpdates\UpdateCheckStatus;
use App\Models\AppUpdates\AppUpdate;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\ServiceInstance;
use App\Services\AppUpdates\UpdatePlan;
use App\Support\Images\ImageReference;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Starts automatic updates (docs/04 §14.3) for instances whose maintenance
 * window is open: the biggest candidate their setting allows (never a
 * major), first seen at least 48 hours ago, not failed before on this
 * instance, with a backup first when the service has data. Anything in the
 * way (staged changes, a deploy, no backup storage) means: not this time.
 */
final readonly class StartAutomaticUpdates
{
    public const int SETTLE_HOURS = 48;

    public function __construct(
        private UpdatePlan $plan,
        private StartAppUpdate $start,
    ) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $started = 0;

        $instances = ServiceInstance::query()
            ->where('auto_update', '!=', AutoUpdateMode::Off->value)
            ->whereNull('removed_at')
            ->whereNull('stopped_at')
            ->whereNotNull('active_deployment_id')
            ->whereHas('updateCheck', static fn (Builder $query) => $query->where('status', UpdateCheckStatus::Available->value))
            ->with(['service', 'environment', 'updateCheck', 'volumes'])
            ->orderBy('id')
            ->get();

        foreach ($instances as $instance) {
            $candidate = $this->due($instance, $now);

            if (! $candidate instanceof UpdateCandidateData) {
                continue;
            }

            $update = $this->start->handle($instance, new StartAppUpdateData($candidate->tag), null, AuditActor::system(), automatic: true);
            $started += $update instanceof AppUpdate ? 1 : 0;
        }

        return $started;
    }

    private function due(ServiceInstance $instance, CarbonImmutable $now): ?UpdateCandidateData
    {
        $check = $instance->updateCheck;
        $window = $instance->auto_update_window ?? UpdateWindowData::default();

        if (! $check instanceof AppUpdateCheck || ! UpdatePlan::isChecked($instance) || ! $window->contains($now)) {
            return null;
        }

        // An update since the check: wait for the check that follows it.
        if (AppUpdate::query()->where('service_instance_id', $instance->id)->where('started_at', '>=', $check->checked_at ?? $now)->exists()) {
            return null;
        }

        $candidate = $this->candidate($instance, $check, $now);

        if (! $candidate instanceof UpdateCandidateData || $this->plan->blocker($instance) !== null) {
            return null;
        }

        if ($this->plan->backupSubjects($instance) !== [] && ! $this->plan->hasBackupStorage($instance)) {
            return null;
        }

        return $candidate;
    }

    private function candidate(ServiceInstance $instance, AppUpdateCheck $check, CarbonImmutable $now): ?UpdateCandidateData
    {
        $repository = ImageReference::tryParse((string) $check->image);
        $best = null;

        foreach ($check->candidates as $candidate) {
            if (! $instance->auto_update->allows($candidate->kind) || $candidate->digest === null || ! $repository instanceof ImageReference) {
                continue;
            }

            if (CarbonImmutable::parse($candidate->firstSeenAt)->greaterThan($now->subHours(self::SETTLE_HOURS))) {
                continue;
            }

            $target = $repository->registry.'/'.$repository->repository.':'.$candidate->tag.'@'.$candidate->digest;
            $failedBefore = AppUpdate::query()
                ->where('service_instance_id', $instance->id)
                ->where('to_reference', $target)
                ->whereIn('status', [AppUpdateStatus::Failed->value, AppUpdateStatus::RolledBack->value])
                ->exists();

            if (! $failedBefore && ($best === null || $candidate->kind->rank() > $best->kind->rank())) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
