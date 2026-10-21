<?php

declare(strict_types=1);

namespace App\Queries;

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
use App\Enums\Permission;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Connection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Variables\SpecVariableResolver;
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
        // Keys launchroom.json sets in this environment (docs/18 §3.5).
        $managed = EnvironmentConfigFile::query()->where('environment_id', $environment->id)->first()?->managed->service($service->public_id)->variables ?? [];

        if (! $serviceSpec instanceof ServiceSpec) {
            throw (new ModelNotFoundException)->setModel(Service::class);
        }

        $scopes = $this->resolver->annotatedScopes($project, $spec);
        $preview = new ReferencePreview($scopes);

        $ids = [...array_keys($serviceSpec->variables), ...array_keys($serviceSpec->connectionVariables)];
        /** @var array<string, Variable> $models */
        $models = Variable::query()
            ->where('project_id', $project->id)
            ->whereIn('public_id', $ids)
            ->with(['targets', 'connection:id,public_id,from_service_id,to_service_id', 'connection.to:id,name'])
            ->get()
            ->keyBy('public_id')
            ->all();

        $environments = $project->environments()->orderByRaw("CASE kind WHEN 'production' THEN 0 WHEN 'persistent' THEN 1 ELSE 2 END")->orderBy('name')->get(['id', 'public_id', 'name', 'kind']);
        $environmentNames = $environments->mapWithKeys(static fn (Environment $env): array => [$env->id => $env->name])->all();
        $environmentIds = $environments->mapWithKeys(static fn (Environment $env): array => [$env->id => $env->public_id])->all();

        $canRevealSecrets = $user->hasTeamPermission($project->team_id, Permission::RevealSecrets);
        $winners = $this->winners($serviceSpec);
        $rows = [];

        foreach ([...$serviceSpec->connectionVariables, ...$serviceSpec->variables] as $id => $variable) {
            $model = $models[$id] ?? null;

            if ($model === null) {
                continue;
            }

            $rows[] = $this->row($project, $variable, $model, $serviceSpec, $preview, $winners, $environmentNames, $environmentIds, $user, $managed);
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
            );
        }

        $system = [];

        foreach (SystemVariables::for($spec->environment, $serviceSpec, $serviceSpec->deploy->port) as $key => $value) {
            $system[] = new SystemVariableData($key, $value);
        }

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
            canWrite: Gate::allows('create', [Variable::class, $project]),
            canRevealSecrets: $canRevealSecrets,
            secretPlaceholder: self::SECRET_PLACEHOLDER,
        );
    }

    /**
     * @param  array<string, string>  $winners  key => id of the row whose value applies
     * @param  array<int, string>  $environmentNames
     * @param  array<int, string>  $environmentIds
     * @param  list<string>  $managed  keys launchroom.json sets
     */
    private function row(Project $project, VariableSpec $variable, Variable $model, ServiceSpec $service, ReferencePreview $preview, array $winners, array $environmentNames, array $environmentIds, User $user, array $managed = []): VariableRowData
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
            canEdit: Gate::forUser($user)->allows('update', $model) && (! in_array($variable->key, $managed, true) || $isSecret),
            canReveal: Gate::forUser($user)->allows('reveal', $model),
            managedByFile: $model->connection_id === null && in_array($variable->key, $managed, true),
        );
    }

    /**
     * Which row's value applies per key (docs/06 §3.1 precedence:
     * connection < environment-targeted < branch override).
     *
     * @return array<string, string>
     */
    private function winners(ServiceSpec $service): array
    {
        $winners = [];

        foreach ($service->connectionVariables as $id => $variable) {
            $winners[$variable->key] = $id;
        }

        foreach ([VariableSpec::LAYER_ENVIRONMENT, VariableSpec::LAYER_BRANCH] as $layer) {
            foreach ($service->variables as $id => $variable) {
                if ($variable->layer === $layer) {
                    $winners[$variable->key] = $id;
                }
            }
        }

        return $winners;
    }
}
