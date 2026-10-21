<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\DetectedPlanData;
use App\Data\ConfigAsCode\DeploymentConfigErrorsData;
use App\Data\Deployments\DeploymentDetailData;
use App\Data\Deployments\DeploymentFailureData;
use App\Data\Deployments\DeploymentRefData;
use App\Data\Deployments\DeploymentStepData;
use App\Data\Deployments\EnvSnapshotEntryData;
use App\Data\Deployments\FailureExplanationData;
use App\Data\Deployments\ResolvedConfigEntryData;
use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\WorkloadPhase;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Server;
use App\Models\Service;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Git\GitProviders;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Deployments\Failures\FailureCatalogue;
use App\Support\Deployments\Failures\FailureContext;
use App\Support\Deployments\PullProgressMessage;
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
        // While new workloads pull their image, the running health step says how far (docs/04 §11 "Pulls").
        $pulling = $stepRows->contains(static fn (DeploymentStep $step): bool => $step->name === DeploymentStepName::Health && $step->status === DeploymentStepStatus::Running)
            ? PullProgressMessage::for($deployment->placements()->where('phase', WorkloadPhase::Pulling->value)->get())
            : null;
        $steps = array_values($stepRows->map(static fn (DeploymentStep $step): DeploymentStepData => new DeploymentStepData(
            name: $step->name,
            status: $step->status,
            startedAt: $step->started_at,
            finishedAt: $step->finished_at,
            message: isset($step->meta['message']) && is_string($step->meta['message'])
                ? mb_substr($step->meta['message'], 0, 2000)
                : ($step->name === DeploymentStepName::Health && $step->status === DeploymentStepStatus::Running ? $pulling : null),
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
            canRedeploy: Gate::allows('redeploy', $deployment),
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
        );
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
     * @return array{logo: string, builtOn: string|null, serving: DeploymentRefData|null, previous: DeploymentRefData|null, compareUrl: string|null}
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

        $builtOn = $deployment->build_server_id === null ? null : Server::query()->where('team_id', $deployment->team_id)->whereKey($deployment->build_server_id)->value('name');

        return [
            'logo' => ServiceGlyph::logo($service, $framework),
            'builtOn' => is_string($builtOn) ? $builtOn : null,
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
