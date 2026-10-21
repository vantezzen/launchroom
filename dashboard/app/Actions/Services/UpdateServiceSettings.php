<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Data\Services\ScheduleSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\PlanServiceJob;
use App\Models\ServiceInstance;
use App\Services\Deployments\PlacementConstraints;
use App\Services\Dockerfiles\GeneratedDockerfiles;
use App\Support\Cron\CronGrammar;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Edits a service instance's settings. The edit is staged: it goes live with
 * the next Deploy (docs/06 §4).
 */
final readonly class UpdateServiceSettings
{
    public function __construct(
        private PlacementConstraints $constraints,
        private GeneratedDockerfiles $generated,
    ) {}

    public function handle(ServiceInstance $instance, UpdateServiceSettingsData $data): ServiceInstance
    {
        return DB::transaction(function () use ($instance, $data): ServiceInstance {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with('service')->lockForUpdate()->firstOrFail();

            if (! $data->scaling instanceof Optional && $instance->service->kind->isStateful() && $data->scaling->replicas > 1) {
                throw DomainRuleViolation::on('scaling.replicas', 'Databases run a single replica: two instances can\'t share one data directory.');
            }

            if (! $data->deploy instanceof Optional && $data->deploy->schedule instanceof ScheduleSettings) {
                $this->checkSchedule($instance->service->kind, $data->deploy->schedule);
            }

            // A generated Dockerfile (docs/20 §5) only from a verified run of this service.
            if (! $data->build instanceof Optional && $data->build->generatedDockerfile !== null && $data->build->generatedDockerfile !== $instance->build->generatedDockerfile) {
                $this->generated->contentFor($instance->team_id, $instance->service->public_id, $data->build->generatedDockerfile);
            }

            if (! $data->scaling instanceof Optional) {
                // Label grammar, known servers, and at least one server satisfying them.
                $data->scaling = $this->constraints->validate($instance, $data->scaling);
            }

            $instance->forceFill(array_filter([
                'build' => $data->build,
                'deploy' => $data->deploy,
                'scaling' => $data->scaling,
                'enabled' => $data->enabled,
                'branch_override' => $data->branchOverride,
            ], static fn (mixed $value): bool => ! $value instanceof Optional));

            // Build inputs changed: refresh the plan preview (docs/04 §4).
            $replan = $instance->isDirty(['build', 'branch_override']) && $instance->service->kind === ServiceKind::App;
            $instance->save();

            if ($replan) {
                dispatch(new PlanServiceJob($instance->id))->afterCommit();
            }

            return $instance;
        });
    }

    /**
     * Every entry point (settings, API, launchroom.json) goes through here:
     * the same grammar launchroomd enforces (docs/04 §1).
     */
    private function checkSchedule(ServiceKind $kind, ScheduleSettings $schedule): void
    {
        if ($kind !== ServiceKind::Cron) {
            throw DomainRuleViolation::on('deploy.schedule', 'Only cron services run on a schedule.');
        }

        if (($problem = CronGrammar::error($schedule->cron)) !== null) {
            throw DomainRuleViolation::on('deploy.schedule.cron', $problem);
        }

        if (($problem = CronGrammar::timeZoneError($schedule->timeZone)) !== null) {
            throw DomainRuleViolation::on('deploy.schedule.timeZone', $problem);
        }
    }
}
