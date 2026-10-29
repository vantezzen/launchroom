<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\DetectedPlanData;
use App\Data\ConfigAsCode\DeploymentConfigErrorsData;
use App\Data\Deployments\BuildServerData;
use App\Data\Deployments\DeploymentDetailData;
use App\Data\Deployments\DeploymentFailureData;
use App\Data\Deployments\DeploymentRefData;
use App\Data\Deployments\DeploymentReplicaData;
use App\Data\Deployments\DeploymentServerData;
use App\Data\Deployments\DeploymentStepData;
use App\Data\Deployments\EnvSnapshotEntryData;
use App\Data\Deployments\FailureExplanationData;
use App\Data\Deployments\ResolvedConfigEntryData;
use App\Data\Git\RepositoryRef;
use App\Data\Servers\DeviceData;
use App\Data\Services\GitSource;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepStatus;
use App\Enums\WorkloadPhase;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Deployments\ReleaseStep;
use App\Services\Git\GitProviders;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Deployments\Failures\FailureCatalogue;
use App\Support\Deployments\Failures\FailureContext;
use App\Support\Deployments\RolloutProgress;
use Illuminate\Support\Facades\Gate;

/**
 * The deployment detail page (docs/08 §3.5): summary, step timeline,
 * failure with hint, the configuration it was deployed from (its revision)
 * and its env snapshot as names plus keyed hashes (docs/06 §3.5), never values.
 */
final readonly class DeploymentDetailView
{
    public function __construct(
        private DeploymentSummaries $summaries,
        private ProjectKeyring $keyring,
        private DeploymentStats $stats,
        private GitProviders $providers,
    ) {}

    public function get(Deployment $deployment): DeploymentDetailData
    {
        $deployment = DeploymentSummaries::withRelations(Deployment::query()->whereKey($deployment->id))
            ->with(['revision', 'environment.project:id,public_id,name,team_id'])
            ->firstOrFail();
        $project = $deployment->environment->project;

        $stepRows = $deployment->steps()->orderBy('id')->get();
        // What the running Roll out or Health check step is doing right now
        // ("Waiting for server-2 to pick up the change", "Downloading image
        // (412 MB of 690 MB)", "Waiting for GET / on :8000 to answer
        // (attempt 3: connection refused)"; D68).
        $progress = RolloutProgress::for($deployment, $stepRows);
        $steps = array_values($stepRows->map(static fn (DeploymentStep $step): DeploymentStepData => DeploymentStepData::fromStep(
            $step,
            $step->status === DeploymentStepStatus::Running ? ($progress[$step->name->value] ?? null) : null,
        ))->all());

        $service = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
        $failed = $deployment->status === DeploymentStatus::Failed && $deployment->failure_class !== null;

        $related = $this->related($deployment);

        return new DeploymentDetailData(
            deployment: $this->summaries->one($deployment),
            projectName: $project->name,
            revision: $deployment->revision?->number,
            steps: $steps,
            failure: $failed
                ? DeploymentFailureData::for($deployment->failure_class, $deployment->failure_message)
                : null,
            config: $service instanceof ServiceSpec ? $this->config($service) : [],
            env: $this->env($deployment),
            // A stopped service starts from its panel (D68), not with Redeploy.
            canRedeploy: $deployment->serviceInstance->stopped_at === null && Gate::allows('redeploy', $deployment),
            canCancel: Gate::allows('cancel', $deployment),
            canPromote: Gate::allows('promote', $deployment),
            failureExplanation: $failed ? $this->explain($deployment, array_values($stepRows->all()), $service instanceof ServiceSpec ? $service : null) : null,
            buildHistory: $this->stats->buildHistory($deployment->serviceInstance),
            logo: $related['logo'],
            builtOn: $related['builtOn'],
            serving: $related['serving'],
            previous: $related['previous'],
            compareUrl: $related['compareUrl'],
            configErrors: DeploymentConfigErrorsData::fromStored($deployment->config_errors),
            buildServer: $related['buildServer'],
            servers: $this->servers($deployment),
            supersededBy: $this->supersededBy($deployment),
            expectsRelease: ReleaseStep::expected($deployment),
        );
    }

    private function supersededBy(Deployment $deployment): ?DeploymentRefData
    {
        $next = $deployment->superseded_by_id === null ? null : Deployment::query()
            ->where('team_id', $deployment->team_id)
            ->whereKey($deployment->superseded_by_id)
            ->first();

        return $next instanceof Deployment ? DeploymentRefData::of($next) : null;
    }

    /**
     * The servers that hold the deployment's replicas, each with its
     * replicas in slot order (the stage of the overview card). Empty once
     * they're gone: superseded, failed and cancelled deployments keep none.
     *
     * @return list<DeploymentServerData>
     */
    private function servers(Deployment $deployment): array
    {
        $placements = $deployment->placements()
            ->where('team_id', $deployment->team_id)
            ->where('phase', '!=', WorkloadPhase::Removed->value)
            ->orderBy('slot')
            ->get();

        if ($placements->isEmpty()) {
            return [];
        }

        $servers = Server::query()->where('team_id', $deployment->team_id)->whereIn('id', $placements->pluck('server_id')->unique()->all())->get()->keyBy('id');
        $out = [];

        foreach ($placements->groupBy('server_id') as $serverId => $group) {
            $server = $servers->get($serverId);

            if (! $server instanceof Server) {
                continue;
            }

            $out[] = new DeploymentServerData(
                id: $server->public_id,
                name: $server->name,
                device: DeviceData::fromServer($server),
                status: $server->status,
                replicas: array_values($group->map(static fn (Placement $placement): DeploymentReplicaData => DeploymentReplicaData::of($placement))->all()),
            );
        }

        return $out;
    }

    /**
     * A known failure in plain words (FailureCatalogue): the failure message,
     * failed step messages and the known-error codes of the build task.
     *
     * @param  list<DeploymentStep>  $steps
     */
    private function explain(Deployment $deployment, array $steps, ?ServiceSpec $service): ?FailureExplanationData
    {
        if ($deployment->failure_class === null) {
            return null;
        }

        $build = DaemonTask::query()
            ->where('deployment_id', $deployment->id)
            ->where('type', DaemonTaskType::Build->value)
            ->latest('id')
            ->first(['id', 'result']);
        $failure = $build?->result['failure'] ?? null;
        $hints = is_array($failure) ? ($failure['hints'] ?? []) : [];
        $codes = [];

        foreach (is_array($hints) ? $hints : [] as $hint) {
            if (is_array($hint) && is_string($hint['code'] ?? null)) {
                $codes[] = $hint['code'];
            }
        }

        $stepMessages = [];

        foreach ($steps as $step) {
            if ($step->status === DeploymentStepStatus::Failed && is_string($step->meta['message'] ?? null)) {
                $stepMessages[] = $step->meta['message'];
            }
        }

        if ($build !== null) {
            $tail = LogChunk::query()->where('team_id', $deployment->team_id)
                ->where('daemon_task_id', $build->id)->latest('id')->limit(8)->get(['lines'])
                ->reverse()->flatMap(static fn (LogChunk $chunk): array => array_column($chunk->lines, 'text'))->implode("\n");
            $stepMessages[] = mb_substr($tail, -16000);
        }

        return FailureCatalogue::explain(new FailureContext(
            class: $deployment->failure_class,
            message: $deployment->failure_message,
            hintCodes: $codes,
            logExcerpt: $stepMessages === [] ? null : implode("\n", $stepMessages),
            currentPort: $service?->deploy->port,
            healthStartPeriodSeconds: $service?->deploy->healthcheck->startPeriodSeconds,
        ));
    }

    /**
     * Logo, build server, the live and the previous deployment of the
     * service and a commit-range link (overview card and related cards).
     *
     * @return array{logo: string, builtOn: string|null, buildServer: BuildServerData|null, serving: DeploymentRefData|null, previous: DeploymentRefData|null, compareUrl: string|null}
     */
    private function related(Deployment $deployment): array
    {
        $instance = $deployment->serviceInstance;
        // The summary relations select a few columns only: load the whole service.
        $service = Service::query()->whereKey($instance->service_id)->firstOrFail();
        $summary = $deployment->plan['summary'] ?? null;
        $framework = is_array($summary) && is_string($summary['framework'] ?? null) && $summary['framework'] !== '' ? DetectedPlanData::frameworkLabel($summary['framework']) : null;

        $serving = $instance->active_deployment_id !== null && $instance->active_deployment_id !== $deployment->id
            ? Deployment::query()->whereKey($instance->active_deployment_id)->first()
            : null;
        $previous = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->where('id', '<', $deployment->id)
            ->whereIn('status', [DeploymentStatus::Active->value, DeploymentStatus::Superseded->value])
            ->latest('id')
            ->first();
        $source = $service->source;
        $compare = $source instanceof GitSource && ! $source->isUnconnectedPlainGit()
            && $previous?->commit_sha !== null && $deployment->commit_sha !== null && $previous->commit_sha !== $deployment->commit_sha
            ? $this->providers->forSource($source)->compareUrl(RepositoryRef::fromSource($deployment->team_id, $source), $previous->commit_sha, $deployment->commit_sha)
            : null;

        $serverId = $deployment->build_server_id;
        if ($deployment->status === DeploymentStatus::Building || $serverId === null) {
            $serverId = DaemonTask::query()->where('team_id', $deployment->team_id)
                ->where('deployment_id', $deployment->id)->where('type', DaemonTaskType::Build->value)
                ->whereIn('status', [DaemonTaskStatus::Leased->value, DaemonTaskStatus::Succeeded->value, DaemonTaskStatus::Failed->value])
                ->latest('id')->value('server_id');
        }
        $builtOn = is_int($serverId) ? Server::query()->where('team_id', $deployment->team_id)->whereKey($serverId)->first() : null;

        return [
            'logo' => ServiceGlyph::logo($service, $framework),
            'builtOn' => $builtOn?->name,
            'buildServer' => $builtOn === null ? null : new BuildServerData($builtOn->public_id, $builtOn->name, DeviceData::fromServer($builtOn)),
            'serving' => $serving instanceof Deployment ? DeploymentRefData::of($serving) : null,
            'previous' => $previous instanceof Deployment ? DeploymentRefData::of($previous) : null,
            'compareUrl' => $compare,
        ];
    }

    /**
     * @return list<ResolvedConfigEntryData>
     */
    private function config(ServiceSpec $service): array
    {
        $entries = [];
        $groups = [
            'Source' => $service->source,
            'Build' => $service->kind->isBuilt() ? $service->build->toArray() : [],
            'Deploy' => $service->deploy->toArray(),
            'Scaling' => $service->scaling->toArray(),
        ];

        foreach ($groups as $group => $values) {
            foreach (self::flatten($values) as $key => $value) {
                $entries[] = new ResolvedConfigEntryData($group, $key, $value);
            }
        }

        return $entries;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $name = $prefix.$key;

            if (is_array($value) && ! array_is_list($value)) {
                $flat = [...$flat, ...self::flatten($value, $name.'.')];

                continue;
            }

            $flat[$name] = match (true) {
                $value === null => 'Default',
                is_bool($value) => $value ? 'Yes' : 'No',
                is_string($value) => $value === '' ? 'Default' : $value,
                is_int($value), is_float($value) => (string) $value,
                is_array($value) => $value === [] ? 'None' : implode(' ', array_map(static fn (mixed $part): string => is_scalar($part) ? (string) $part : (string) json_encode($part), $value)),
                default => (string) json_encode($value),
            };
        }

        return $flat;
    }

    /**
     * @return list<EnvSnapshotEntryData>
     */
    private function env(Deployment $deployment): array
    {
        if ($deployment->env_snapshot === null) {
            return [];
        }

        $project = $deployment->environment->project;
        $env = $this->keyring->decryptSnapshot($project, $deployment->public_id, $deployment->env_snapshot);
        ksort($env);
        $entries = [];

        foreach ($env as $name => $value) {
            $entries[] = new EnvSnapshotEntryData((string) $name, substr($this->keyring->mac($project, 'env-snapshot-display', $name."\0".$value), 0, 12));
        }

        return $entries;
    }
}
