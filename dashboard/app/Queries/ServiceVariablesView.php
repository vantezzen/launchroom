<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\DetectedPlanData;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Data\Variables\EnvironmentOptionData;
use App\Data\Variables\ReferenceScopeData;
use App\Data\Variables\ServiceVariablesData;
use App\Data\Variables\SharedVariableRowData;
use App\Data\Variables\SystemVariableData;
use App\Data\Variables\VariableRowData;
use App\Data\Variables\VariableTargetChipData;
use App\Enums\EnvironmentKind;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Variables\ReferencePreview;
use App\Support\Variables\SystemVariables;
use App\Support\Variables\VariableResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Assembles the variables tab of a service in an environment (docs/06 §3.4)
 * from the current intent: the rows that apply here (environment-targeted,
 * branch overrides, connection-owned), the shared variables of this
 * environment with the service's opt-ins, the system variables, and what
 * `${{` can complete to. Config values are included (readable by anyone who
 * can see the service); secret values never are.
 */
final readonly class ServiceVariablesView
{
    /** Raw `.env` mode: stands for "keep the existing secret value". */
    public const string SECRET_PLACEHOLDER = '__LAUNCHROOM_SECRET_UNCHANGED__';

    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private SpecVariableResolver $resolver,
        private ProjectKeyring $keyring,
    ) {}

    public function get(Environment $environment, Service $service, User $user): ServiceVariablesData
    {
        $environment->loadMissing('project');
        $project = $environment->project;
        $spec = $this->currentSpec->get($environment);
        $serviceSpec = $spec->service($service->public_id);
        $stagedRemoval = null;

        if (! $serviceSpec instanceof ServiceSpec) {
            $stagedRemoval = ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $service->id)->whereNotNull('removed_at')->first();
            $spec = $stagedRemoval instanceof ServiceInstance ? $this->currentSpec->get($environment, $stagedRemoval) : $spec;
            $serviceSpec = $spec->service($service->public_id) ?? throw (new ModelNotFoundException)->setModel(Service::class);
        }

        // Keys launchroom.json sets in this environment (docs/18 §3.5).
        $managed = EnvironmentConfigFile::query()->where('environment_id', $environment->id)->first()?->managed->service($service->public_id)->variables ?? [];

        $scopes = $this->resolver->annotatedScopes($project, $spec);
        $preview = new ReferencePreview($scopes);

        // A worker or cron also gets its app's connection variables (read-only here).
        $inherited = $spec->inheritedConnectionVariables($serviceSpec);
        $inheritedFrom = $inherited === [] || $serviceSpec->parentId === null ? null : $spec->service($serviceSpec->parentId)?->name;
        $ids = [...array_keys($serviceSpec->variables), ...array_keys($serviceSpec->connectionVariables), ...array_keys($inherited)];
        /** @var array<string, Variable> $models */
        $models = Variable::query()
            ->where('project_id', $project->id)
            ->whereIn('public_id', $ids)
            ->with(['targets', 'connection:id,public_id,from_service_id,to_service_id', 'connection.to:id,name'])
            ->get()
            ->keyBy('public_id')
            ->all();

        $environments = $project->environments()->inDisplayOrder()->get(['id', 'public_id', 'name', 'kind']);
        $environmentNames = $environments->mapWithKeys(static fn (Environment $env): array => [$env->id => $env->name])->all();
        $environmentIds = $environments->mapWithKeys(static fn (Environment $env): array => [$env->id => $env->public_id])->all();

        $canRevealSecrets = Gate::forUser($user)->allows('revealSecrets', [Variable::class, $project]);
        $winners = [];

        foreach ($spec->variablesInPrecedence($serviceSpec) as $variable) {
            $winners[$variable->key] = $variable->id;
        }

        $rows = [];

        foreach ([...$serviceSpec->connectionVariables, ...$serviceSpec->variables] as $id => $variable) {
            $model = $models[$id] ?? null;

            if ($model === null) {
                continue;
            }

            $rows[] = $this->row($project, $variable, $model, $serviceSpec, $preview, $winners, $environmentNames, $environmentIds, $user, $managed);
        }

        foreach ($inherited as $id => $variable) {
            $model = $models[$id] ?? null;

            if ($model !== null) {
                $rows[] = $this->row($project, $variable, $model, $serviceSpec, $preview, $winners, $environmentNames, $environmentIds, $user, inheritedFrom: $inheritedFrom);
            }
        }

        usort($rows, static fn (VariableRowData $a, VariableRowData $b): int => [$a->key, $a->connection !== null] <=> [$b->key, $b->connection !== null]);

        $shared = [];

        foreach ($spec->sharedVariables as $id => $variable) {
            $isSecret = $variable->type === VariableType::Secret;
            $value = $isSecret ? null : $this->keyring->decryptVariable($project, $variable->id, $variable->version, $variable->ciphertext);
            $result = $value === null ? null : $preview->preview(VariableResolver::SHARED, $variable->key, $value);

            $shared[] = new SharedVariableRowData(
                id: $id,
                key: $variable->key,
                type: $variable->type,
                value: $value,
                preview: $result !== null && $variable->references !== [] ? $result['preview'] : null,
                errors: $result['errors'] ?? [],
                used: in_array($id, $serviceSpec->sharedVariableIds, true),
                exposure: $variable->exposure,
            );
        }

        $system = $this->system($project, $environment, $service, $spec, $serviceSpec, $winners);

        $referenceScopes = [new ReferenceScopeData(null, $serviceSpec->slug.' (this service)', array_keys($scopes[$serviceSpec->slug] ?? []))];

        foreach ($spec->services as $other) {
            if ($other->id !== $serviceSpec->id) {
                $referenceScopes[] = new ReferenceScopeData($other->slug, $other->name, array_keys($scopes[$other->slug] ?? []));
            }
        }

        $referenceScopes[] = new ReferenceScopeData(VariableResolver::SHARED, 'Shared variables', array_keys($scopes[VariableResolver::SHARED] ?? []));

        return new ServiceVariablesData(
            serviceSlug: $serviceSpec->slug,
            environmentId: $environment->public_id,
            variables: $rows,
            shared: $shared,
            system: $system,
            scopes: $referenceScopes,
            environments: array_values($environments
                ->filter(static fn (Environment $env): bool => $env->kind !== EnvironmentKind::Preview)
                ->map(static fn (Environment $env): EnvironmentOptionData => new EnvironmentOptionData($env->public_id, $env->name, $env->kind))
                ->all()),
            canWrite: $stagedRemoval === null && Gate::allows('create', [Variable::class, $project]),
            canRevealSecrets: $canRevealSecrets,
            secretPlaceholder: self::SECRET_PLACEHOLDER,
        );
    }

    /**
     * The system variables, then the framework names the latest build asks
     * for (APP_URL from PUBLIC_URL) and the listen address its preset sets,
     * as this environment resolves them. A HOST the running deployment has
     * but the next deploy won't (OD12) is listed as going away.
     *
     * @param  array<string, string>  $winners  key => id of the row whose value applies
     * @return list<SystemVariableData>
     */
    private function system(Project $project, Environment $environment, Service $service, EnvironmentSpec $spec, ServiceSpec $serviceSpec, array $winners): array
    {
        $instance = ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $service->id)->with('activeDeployment')->first();
        $summary = $instance?->plan->summary ?? ($instance === null ? null : Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('plan')
            ->latest('id')
            ->first()?->plan?->summary);
        $parent = $serviceSpec->parentId === null ? null : $spec->service($serviceSpec->parentId);
        $values = SystemVariables::for($spec->environment, $serviceSpec, $serviceSpec->deploy->port, $parent);
        // Aliases see the port the deployment runs with (also one the build detected).
        $effective = SystemVariables::for($spec->environment, $serviceSpec, $serviceSpec->deploy->port ?? ($serviceSpec->kind->isWeb() ? $summary?->port : null), $parent);
        $rows = [];

        foreach ($values as $key => $value) {
            $rows[$key] = new SystemVariableData($key, $value, overridden: isset($winners[$key]));
        }

        if ($summary instanceof BuildSummary) {
            $framework = $summary->framework === null ? 'the framework' : DetectedPlanData::frameworkLabel($summary->framework);

            foreach ($summary->envAliases ?? [] as $alias) {
                $value = SystemVariables::source($alias->from, $effective, $environment->network_subnet);

                if ($value !== null && $value !== '' && ! isset($rows[$alias->name])) {
                    $rows[$alias->name] = new SystemVariableData($alias->name, $value, "Set from {$alias->from->value} for {$framework}", isset($winners[$alias->name]));
                }
            }

            if (EffectiveDeploy::listensOnAll($summary)) {
                foreach (['HOST', 'HOSTNAME'] as $key) {
                    $rows[$key] ??= new SystemVariableData($key, '0.0.0.0', 'Set so the app listens on all interfaces', isset($winners[$key]));
                }
            }
        }

        $active = $instance?->activeDeployment;

        if (! isset($rows['HOST']) && ! isset($winners['HOST']) && $active?->env_snapshot !== null) {
            $running = $this->keyring->decryptSnapshot($project, $active->public_id, $active->env_snapshot);

            if (($running['HOST'] ?? null) === '0.0.0.0') {
                $rows['HOST'] = new SystemVariableData('HOST', '0.0.0.0', 'No longer set by launchroom. The next deploy runs without it; add HOST as a variable if the app needs it to listen.', removedNextDeploy: true);
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, string>  $winners  key => id of the row whose value applies
     * @param  array<int, string>  $environmentNames
     * @param  array<int, string>  $environmentIds
     * @param  list<string>  $managed  keys launchroom.json sets
     * @param  string|null  $inheritedFrom  the app whose connection injects it (workers and crons)
     */
    private function row(Project $project, VariableSpec $variable, Variable $model, ServiceSpec $service, ReferencePreview $preview, array $winners, array $environmentNames, array $environmentIds, User $user, array $managed = [], ?string $inheritedFrom = null): VariableRowData
    {
        $isSecret = $variable->type === VariableType::Secret;
        // Secrets are decrypted only to check their references; never returned.
        $raw = ! $isSecret || $variable->references !== []
            ? $this->keyring->decryptVariable($project, $variable->id, $variable->version, $variable->ciphertext)
            : null;
        $value = $isSecret ? null : $raw;
        $result = $raw === null ? null : $preview->preview($service->slug, $variable->key, $raw);

        $connection = $model->connection instanceof Connection ? $model->connection->to->name : null;

        return new VariableRowData(
            id: $variable->id,
            key: $variable->key,
            type: $variable->type,
            value: $value,
            exposure: $variable->exposure,
            exposureOverridden: $model->exposure_override !== null,
            browserInlined: VariableExposure::isBrowserInlined($variable->key),
            generated: $variable->generated,
            references: $variable->references,
            preview: ! $isSecret && $result !== null && $variable->references !== [] ? $result['preview'] : null,
            errors: $result['errors'] ?? [],
            connection: $connection,
            overridden: ($winners[$variable->key] ?? $variable->id) !== $variable->id,
            targets: $connection !== null ? [] : array_values($model->targets->map(static fn (VariableTarget $target): VariableTargetChipData => new VariableTargetChipData(
                environmentId: $target->environment_id === null ? null : ($environmentIds[$target->environment_id] ?? null),
                label: $target->all_previews
                    ? ($target->branch_pattern === null ? 'All previews' : "Previews: {$target->branch_pattern}")
                    : ($environmentNames[$target->environment_id ?? 0] ?? 'Unknown'),
                allPreviews: $target->all_previews,
                branchPattern: $target->branch_pattern,
            ))->all()),
            // A secret the file requires keeps its value in the dashboard.
            canEdit: $inheritedFrom === null && Gate::forUser($user)->allows('update', $model) && (! in_array($variable->key, $managed, true) || $isSecret),
            canReveal: Gate::forUser($user)->allows('reveal', $model),
            managedByFile: $model->connection_id === null && in_array($variable->key, $managed, true),
            inheritedFrom: $inheritedFrom,
        );
    }
}
