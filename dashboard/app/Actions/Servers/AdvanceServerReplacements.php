<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Deployments\RedeployService;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ServerReplacementStatus;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\RestoreRun;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServerReplacement;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Registry\RegistryManifests;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves "Replace server" runs forward (docs/14 §5.3), right after
 * ReplaceServer and on every backup tick:
 *
 * 1. stateful services are redeployed (pinned to the replacement through
 *    their volumes); their queued restores start once they run there
 *    (AdvanceRestores) and finish as `restored` / `failed`;
 * 2. once every stateful service is done, stateless services that ran on
 *    the dead server are redeployed (re-placed by the scheduler), unless
 *    they already run elsewhere — dependents start after their data;
 * 3. when everything is done the run completes.
 *
 * In recovery mode (control plane restored, docs/14 §5.2 step e) a service
 * whose active image is no longer in the registry is rebuilt from its
 * recorded commit instead of reusing the lost image.
 */
final readonly class AdvanceServerReplacements
{
    private const array DONE = ['done', 'restored', 'failed', 'gone', 'running'];

    public function __construct(
        private RedeployService $redeploy,
        private RegistryManifests $manifests,
    ) {}

    public function handle(?int $id = null): int
    {
        /** @var list<int> $ids */
        $ids = ServerReplacement::query()
            ->where('status', ServerReplacementStatus::Running->value)
            ->when($id !== null, static fn ($query) => $query->whereKey($id))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $advanced = 0;

        foreach ($ids as $replacementId) {
            try {
                $advanced += $this->advance($replacementId) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $advanced;
    }

    private function advance(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $replacement = ServerReplacement::query()->whereKey($id)->lockForUpdate()->first();

            if (! $replacement instanceof ServerReplacement || $replacement->status !== ServerReplacementStatus::Running) {
                return false;
            }

            $user = $replacement->created_by === null ? null : User::query()->whereKey($replacement->created_by)->first();
            $services = $replacement->services;
            $before = $services;

            foreach ($services as $index => $service) {
                if ($service['kind'] === 'stateful') {
                    $services[$index] = $this->stateful($replacement, $service, $user);
                }
            }

            $statefulDone = array_all($services, static fn (array $service): bool => $service['kind'] !== 'stateful' || in_array($service['status'], self::DONE, true));

            if ($statefulDone) {
                foreach ($services as $index => $service) {
                    if ($service['kind'] === 'stateless') {
                        $services[$index] = $this->stateless($replacement, $service, $user);
                    }
                }
            }

            $replacement->forceFill(['services' => $services])->save();

            if (array_all($services, static fn (array $service): bool => in_array($service['status'], self::DONE, true))) {
                $failed = array_filter($services, static fn (array $service): bool => $service['status'] === 'failed');
                $replacement->transitionTo(ServerReplacementStatus::Completed, $failed === [] ? null : count($failed).' of '.count($services).' services need attention.');

                return true;
            }

            return $services !== $before;
        });
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function stateful(ServerReplacement $replacement, array $service, ?User $user): array
    {
        if (in_array($service['status'], self::DONE, true)) {
            return $service;
        }

        $instance = $this->instance($replacement, $service);

        if (! $instance instanceof ServiceInstance) {
            return [...$service, 'status' => 'gone', 'message' => 'The service no longer exists.'];
        }

        if ($service['deployment'] === null) {
            return $this->deploy($replacement, $instance, $service, $user);
        }

        /** @var list<string> $runIds */
        $runIds = is_array($service['restoreRuns'] ?? null) ? $service['restoreRuns'] : [];
        $runs = RestoreRun::query()->whereIn('public_id', $runIds)->get();
        $deployment = Deployment::query()->where('public_id', $service['deployment'])->first();

        if ($deployment instanceof Deployment && in_array($deployment->status, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            return [...$service, 'status' => 'failed', 'message' => 'The deployment on the replacement failed: '.($deployment->failure_message ?? $deployment->status->value)];
        }

        if ($runs->isEmpty()) {
            return $deployment?->status === DeploymentStatus::Active ? [...$service, 'status' => 'done'] : [...$service, 'status' => 'deploying'];
        }

        $failed = $runs->first(static fn (RestoreRun $run): bool => $run->status === RestoreRunStatus::Failed);

        if ($failed instanceof RestoreRun) {
            return [...$service, 'status' => 'failed', 'message' => 'The restore failed: '.($failed->error ?? 'unknown error')];
        }

        if ($runs->every(static fn (RestoreRun $run): bool => $run->status === RestoreRunStatus::Succeeded)) {
            return [...$service, 'status' => 'restored', 'message' => null];
        }

        return [...$service, 'status' => $runs->contains(static fn (RestoreRun $run): bool => $run->status === RestoreRunStatus::Restoring) ? 'restoring' : 'deploying'];
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function stateless(ServerReplacement $replacement, array $service, ?User $user): array
    {
        if (in_array($service['status'], self::DONE, true)) {
            return $service;
        }

        $instance = $this->instance($replacement, $service);

        if (! $instance instanceof ServiceInstance) {
            return [...$service, 'status' => 'gone', 'message' => 'The service no longer exists.'];
        }

        if ($service['deployment'] === null) {
            // Already re-placed (e.g. by automatic rescheduling of stateless replicas).
            $elsewhere = $instance->active_deployment_id !== null && Placement::query()
                ->where('deployment_id', $instance->active_deployment_id)
                ->where('role', WorkloadRole::Serving->value)
                ->whereIn('phase', [WorkloadPhase::Running->value, WorkloadPhase::Healthy->value])
                ->whereHas('server', static fn (Builder $query) => $query->where('status', ServerStatus::Online->value))
                ->exists();

            return $elsewhere ? [...$service, 'status' => 'running', 'message' => 'Already running on another server.'] : $this->deploy($replacement, $instance, $service, $user);
        }

        $deployment = Deployment::query()->where('public_id', $service['deployment'])->first();

        return match ($deployment?->status) {
            DeploymentStatus::Active => [...$service, 'status' => 'done'],
            DeploymentStatus::Failed, DeploymentStatus::Cancelled => [...$service, 'status' => 'failed', 'message' => 'The deployment failed: '.($deployment->failure_message ?? $deployment->status->value)],
            null => [...$service, 'status' => 'failed', 'message' => 'The deployment no longer exists.'],
            default => [...$service, 'status' => 'deploying'],
        };
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function deploy(ServerReplacement $replacement, ServiceInstance $instance, array $service, ?User $user): array
    {
        try {
            if ($replacement->recovery) {
                $this->forgetLostImages($instance);
            }

            $deployment = $this->redeploy->handle($instance, null, $user, AuditActor::system());
        } catch (DomainRuleViolation $e) {
            return [...$service, 'status' => 'failed', 'message' => $e->getMessage()];
        }

        $restoring = is_array($service['restoreRuns'] ?? null) && $service['restoreRuns'] !== [];

        return [...$service, 'deployment' => $deployment->public_id, 'status' => $restoring ? 'restoring' : 'deploying'];
    }

    /**
     * Older deployments whose image is gone from the registry must not be
     * reused: the redeploy builds from the recorded commit instead.
     */
    private function forgetLostImages(ServiceInstance $instance): void
    {
        $deployments = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('build_key')
            ->whereNotNull('image')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($deployments as $deployment) {
            if ($deployment->image !== null && $this->manifests->exists($deployment->image) === false) {
                $deployment->forceFill(['build_key' => null, 'image_pruned_at' => $deployment->image_pruned_at ?? now()])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function instance(ServerReplacement $replacement, array $service): ?ServiceInstance
    {
        return ServiceInstance::query()
            ->where('team_id', $replacement->team_id)
            ->where('public_id', is_string($service['instance'] ?? null) ? $service['instance'] : '')
            ->first();
    }
}
