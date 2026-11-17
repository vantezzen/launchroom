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
use App\Data\Protocol\V1\BuildWarning;
use App\Data\Servers\DeviceData;
use App\Data\Services\GitSource;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\FailureFixAction;
use App\Enums\WorkloadPhase;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\LogChunk;
use App\Models\Placement;
use App\Models\Server;
use App\Models\Service;
use App\Queries\ConfigAsCode\ConfigFileLinks;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Deployments\DeploymentLogMasker;
use App\Services\Deployments\ReleaseStep;
use App\Services\Deployments\RolloutStatus;
use App\Services\Git\GitProviders;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Deployments\Failures\FailureCatalogue;
use App\Support\Deployments\Failures\FailureContext;
use Illuminate\Support\Collection;
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
        private ConfigFileLinks $configLinks,
        private DeploymentLogMasker $masker,
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
        $progress = $this->rolloutProgress($deployment, $stepRows);
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
            configErrors: $this->configErrors($deployment),
            buildServer: $related['buildServer'],
            servers: $this->servers($deployment),
            supersededBy: $this->supersededBy($deployment),
            expectsRelease: ReleaseStep::expected($deployment),
            newer: $this->neighbour($deployment, newer: true),
            older: $this->neighbour($deployment, newer: false),
        );
    }

    /** The adjacent deployment of the same service instance, by creation order. */
    private function neighbour(Deployment $deployment, bool $newer): ?DeploymentRefData
    {
        $next = Deployment::query()
            ->where('team_id', $deployment->team_id)
            ->where('service_instance_id', $deployment->service_instance_id)
            ->where('id', $newer ? '>' : '<', $deployment->id)
            ->orderBy('id', $newer ? 'asc' : 'desc')
            ->first();

        return $next instanceof Deployment ? DeploymentRefData::of($next) : null;
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
            ->first(['id', 'result', 'server_id']);
        $hints = $build?->result?->failure->hints ?? [];
        $codes = array_map(static fn (BuildWarning $hint): string => $hint->code, $hints);

        // launchroomd's hint messages carry facts the log doesn't ("freed 3.1 GB").
        $stepMessages = array_map(static fn (BuildWarning $hint): string => $hint->message, $hints);
        $listening = [];
        $hintPort = null;
        // The failed replica's facts (RolloutStatus::failureMeta, A3a).
        $facts = [];
        $adopted = false;

        foreach ($steps as $step) {
            if ($step->status !== DeploymentStepStatus::Failed) {
                continue;
            }

            // launchroom already set the port it found (AdoptDetectedPort).
            $adopted = $adopted || isset($step->meta['adoptedAt']);

            if (is_string($step->meta['message'] ?? null)) {
                $stepMessages[] = $step->meta['message'];
            }

            if (is_string($step->meta['portHint'] ?? null)) {
                $codes[] = $step->meta['portHint'];
                $listening = array_values(array_filter((array) ($step->meta['listening'] ?? []), is_int(...)));
                $hintPort = is_int($step->meta['port'] ?? null) ? $step->meta['port'] : null;
            }

            foreach (['failureCode', 'probeHint'] as $key) {
                if (is_string($step->meta[$key] ?? null)) {
                    $codes[] = $step->meta[$key];
                }
            }

            $facts = [...$facts, ...array_intersect_key($step->meta, array_flip(['exitCode', 'oomKilled', 'signal', 'byLaunchroom', 'server', 'probeBody']))];
        }

        if ($build !== null) {
            $tail = LogChunk::query()->where('team_id', $deployment->team_id)
                ->where('daemon_task_id', $build->id)->latest('id')->limit(8)->get(['lines'])
                ->reverse()->flatMap(static fn (LogChunk $chunk): array => array_column($chunk->lines, 'text'))->implode("\n");
            $stepMessages[] = mb_substr($tail, -16000);
        }

        // Where it failed: the replica's server, else the build server (a full disk during the build).
        $serverId = is_string($facts['server'] ?? null) ? $facts['server'] : null;
        $server = $serverId !== null
            ? Server::query()->where('team_id', $deployment->team_id)->where('public_id', $serverId)->first(['id', 'public_id', 'name'])
            : ($build?->server_id !== null ? Server::query()->where('team_id', $deployment->team_id)->whereKey($build->server_id)->first(['id', 'public_id', 'name']) : null);

        // The probe's answer is the app's output: masked like a log line.
        $body = is_string($facts['probeBody'] ?? null) && $facts['probeBody'] !== '' ? $this->masker->mask($deployment->id, $facts['probeBody']) : null;

        $explanation = FailureCatalogue::explain(new FailureContext(
            class: $deployment->failure_class,
            message: $deployment->failure_message,
            hintCodes: array_values(array_unique($codes)),
            logExcerpt: $stepMessages === [] ? null : implode("\n", $stepMessages),
            currentPort: $service?->deploy->port,
            healthStartPeriodSeconds: $service?->deploy->healthcheck->startPeriodSeconds,
            listening: $listening,
            hintPort: $hintPort,
            exitCode: is_int($facts['exitCode'] ?? null) ? $facts['exitCode'] : null,
            oomKilled: ($facts['oomKilled'] ?? false) === true,
            signal: is_int($facts['signal'] ?? null) ? $facts['signal'] : null,
            byLaunchroom: ($facts['byLaunchroom'] ?? false) === true,
            server: $server?->name,
            serverId: $server?->public_id,
            imageUser: $deployment->imageFacts()?->user,
            probeBody: $body,
        ));

        if (! $explanation instanceof FailureExplanationData) {
            return null;
        }

        $action = $adopted && $explanation->action === FailureFixAction::SetPort ? null : $explanation->action;
        $answer = $body !== null && in_array($explanation->key, ['host_not_allowed', 'health_status', 'django_disallowed_host', 'rails_blocked_host'], true) ? $body : null;

        return new FailureExplanationData(
            key: $explanation->key,
            title: $explanation->title,
            cause: $explanation->cause,
            fix: $explanation->fix,
            action: $action,
            params: $action instanceof FailureFixAction ? $explanation->params : [],
            answer: $answer,
        );
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
        $framework = $deployment->plan?->framework();
        $framework = $framework === null ? null : DetectedPlanData::frameworkLabel($framework);

        $serving = $instance->active_deployment_id !== null && $instance->active_deployment_id !== $deployment->id
            ? Deployment::query()->whereKey($instance->active_deployment_id)->first()
            : null;
        $previous = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->where('id', '<', $deployment->id)
            ->wentLive()
            ->latest('id')
            ->first();
        $source = $service->source;
        $compare = $source instanceof GitSource
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

    private function configErrors(Deployment $deployment): ?DeploymentConfigErrorsData
    {
        $errors = $deployment->config_errors === null ? null : clone $deployment->config_errors;

        if ($errors instanceof DeploymentConfigErrorsData) {
            $errors->errors = $this->configLinks->problems($deployment->environment->project, $errors->repository, $errors->commitSha, $errors->path, $errors->errors);
        }

        return $errors;
    }

    /**
     * The running Roll out and Health check steps' current sub-step (D68),
     * from the placements launchroomd reports on.
     *
     * @param  Collection<int, DeploymentStep>  $steps
     * @return array<string, string> step name => what it is doing
     */
    private function rolloutProgress(Deployment $deployment, Collection $steps): array
    {
        $running = $steps->filter(static fn (DeploymentStep $step): bool => $step->status === DeploymentStepStatus::Running
            && in_array($step->name, [DeploymentStepName::Rollout, DeploymentStepName::Health], true));

        if ($running->isEmpty()) {
            return [];
        }

        $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
        $effective = $spec instanceof ServiceSpec ? EffectiveDeploy::for($spec, $deployment) : null;
        $rollout = $steps->first(static fn (DeploymentStep $step): bool => $step->name === DeploymentStepName::Rollout);
        /** @var array<int|string, int> $versions */
        $versions = (array) ($rollout?->meta['versions'] ?? []);
        $status = new RolloutStatus($deployment->placements()->get(), $versions, $effective?->port, $effective?->healthcheck, $deployment->image);
        $out = [];

        foreach ($running as $step) {
            $message = $step->name === DeploymentStepName::Rollout ? $status->rollout() : $status->health();

            if ($message !== null) {
                $out[$step->name->value] = $message;
            }
        }

        return $out;
    }
}
