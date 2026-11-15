<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Deployments\RedeployService;
use App\Data\Audit\AuditActor;
use App\Data\Servers\ReplacedServiceData;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ReplacedServiceKind;
use App\Enums\ReplacedServiceStatus;
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
            $services = array_values($replacement->services->items());
            $before = array_map(static fn (ReplacedServiceData $service): array => $service->toArray(), $services);

            foreach ($services as $index => $service) {
                if ($service->kind === ReplacedServiceKind::Stateful) {
                    $services[$index] = $this->stateful($replacement, $service, $user);
                }
            }

            $statefulDone = array_all($services, static fn (ReplacedServiceData $service): bool => $service->kind !== ReplacedServiceKind::Stateful || $service->status->isDone());

            if ($statefulDone) {
                foreach ($services as $index => $service) {
                    if ($service->kind === ReplacedServiceKind::Stateless) {
                        $services[$index] = $this->stateless($replacement, $service, $user);
                    }
                }
            }

            $replacement->forceFill(['services' => $services])->save();

            if (array_all($services, static fn (ReplacedServiceData $service): bool => $service->status->isDone())) {
                $failed = array_filter($services, static fn (ReplacedServiceData $service): bool => $service->status === ReplacedServiceStatus::Failed);
                $replacement->transitionTo(ServerReplacementStatus::Completed, $failed === [] ? null : count($failed).' of '.count($services).' services need attention.');

                return true;
            }

            return array_map(static fn (ReplacedServiceData $service): array => $service->toArray(), $services) !== $before;
        });
    }

    private function stateful(ServerReplacement $replacement, ReplacedServiceData $service, ?User $user): ReplacedServiceData
    {
        if ($service->status->isDone()) {
            return $service;
        }

        $instance = $this->instance($replacement, $service);

        if (! $instance instanceof ServiceInstance) {
            return $service->changed(['status' => ReplacedServiceStatus::Gone, 'message' => 'The service no longer exists.']);
        }

        if ($service->deployment === null) {
            return $this->deploy($replacement, $instance, $service, $user);
        }

        $runs = RestoreRun::query()->whereIn('public_id', $service->restoreRuns)->get();
        $deployment = $this->latest($service->deployment);

        if ($deployment instanceof Deployment && $this->wentLive($deployment) === false) {
            return $service->changed(['status' => ReplacedServiceStatus::Failed, 'message' => 'The deployment on the replacement failed: '.($deployment->failure_message ?? $deployment->status->value)]);
        }

        if ($runs->isEmpty()) {
            return $service->changed(['status' => $deployment instanceof Deployment && $this->wentLive($deployment) === true ? ReplacedServiceStatus::Done : ReplacedServiceStatus::Deploying]);
        }

        $failed = $runs->first(static fn (RestoreRun $run): bool => $run->status === RestoreRunStatus::Failed);

        if ($failed instanceof RestoreRun) {
            return $service->changed(['status' => ReplacedServiceStatus::Failed, 'message' => 'The restore failed: '.($failed->error ?? 'unknown error')]);
        }

        if ($runs->every(static fn (RestoreRun $run): bool => $run->status === RestoreRunStatus::Succeeded)) {
            return $service->changed(['status' => ReplacedServiceStatus::Restored, 'message' => null]);
        }

        return $service->changed(['status' => $runs->contains(static fn (RestoreRun $run): bool => $run->status === RestoreRunStatus::Restoring) ? ReplacedServiceStatus::Restoring : ReplacedServiceStatus::Deploying]);
    }

    private function stateless(ServerReplacement $replacement, ReplacedServiceData $service, ?User $user): ReplacedServiceData
    {
        if ($service->status->isDone()) {
            return $service;
        }

        $instance = $this->instance($replacement, $service);

        if (! $instance instanceof ServiceInstance) {
            return $service->changed(['status' => ReplacedServiceStatus::Gone, 'message' => 'The service no longer exists.']);
        }

        if ($service->deployment === null) {
            // Already re-placed (e.g. by automatic rescheduling of stateless replicas).
            $elsewhere = $instance->active_deployment_id !== null && Placement::query()
                ->where('deployment_id', $instance->active_deployment_id)
                ->where('role', WorkloadRole::Serving->value)
                ->whereIn('phase', WorkloadPhase::serving())
                ->whereHas('server', static fn (Builder $query) => $query->where('status', ServerStatus::Online->value))
                ->exists();

            return $elsewhere ? $service->changed(['status' => ReplacedServiceStatus::Running, 'message' => 'Already running on another server.']) : $this->deploy($replacement, $instance, $service, $user);
        }

        $deployment = $this->latest($service->deployment);

        if (! $deployment instanceof Deployment) {
            return $service->changed(['status' => ReplacedServiceStatus::Failed, 'message' => 'The deployment no longer exists.']);
        }

        return match ($this->wentLive($deployment)) {
            true => $service->changed(['status' => ReplacedServiceStatus::Done]),
            false => $service->changed(['status' => ReplacedServiceStatus::Failed, 'message' => 'The deployment failed: '.($deployment->failure_message ?? $deployment->status->value)]),
            null => $service->changed(['status' => ReplacedServiceStatus::Deploying]),
        };
    }

    /**
     * The deployment that carries the redeploy now: a restart, rollback or
     * push supersedes a queued one (D77), and its successor takes over.
     */
    private function latest(string $publicId): ?Deployment
    {
        $deployment = Deployment::query()->where('public_id', $publicId)->first();

        for ($hops = 0; $hops < 20 && $deployment?->status === DeploymentStatus::Superseded && $deployment->superseded_by_id !== null; $hops++) {
            $deployment = Deployment::query()->whereKey($deployment->superseded_by_id)->first();
        }

        return $deployment;
    }

    /** True once it went live (a superseded one did, or handed over), false when it failed, null while unfinished. */
    private function wentLive(Deployment $deployment): ?bool
    {
        if (! $deployment->status->isFinished()) {
            return null;
        }

        return $deployment->status === DeploymentStatus::Active || $deployment->status === DeploymentStatus::Superseded;
    }

    private function deploy(ServerReplacement $replacement, ServiceInstance $instance, ReplacedServiceData $service, ?User $user): ReplacedServiceData
    {
        try {
            if ($replacement->recovery) {
                $this->forgetLostImages($instance);
            }

            $deployment = $this->redeploy->handle($instance, null, $user, AuditActor::system());
        } catch (DomainRuleViolation $e) {
            return $service->changed(['status' => ReplacedServiceStatus::Failed, 'message' => $e->getMessage()]);
        }

        $restoring = $service->restoreRuns !== [];

        return $service->changed(['deployment' => $deployment->public_id, 'status' => $restoring ? ReplacedServiceStatus::Restoring : ReplacedServiceStatus::Deploying]);
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

    private function instance(ServerReplacement $replacement, ReplacedServiceData $service): ?ServiceInstance
    {
        return ServiceInstance::query()
            ->where('team_id', $replacement->team_id)
            ->where('public_id', $service->instance)
            ->first();
    }
}
