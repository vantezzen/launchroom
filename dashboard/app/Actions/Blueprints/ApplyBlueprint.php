<?php

declare(strict_types=1);

namespace App\Actions\Blueprints;

use App\Actions\Canvas\AddService;
use App\Actions\Connections\CreateConnection;
use App\Actions\Domains\AddDomain;
use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Variables\CreateVariable;
use App\Data\Audit\AuditActor;
use App\Data\Blueprints\BlueprintBuild;
use App\Data\Blueprints\BlueprintScaling;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Canvas\AddServiceData;
use App\Data\Services\AddVolumeData;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableTargetData;
use App\Enums\ConnectionMode;
use App\Enums\DomainKind;
use App\Enums\ServiceKind;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\CanvasLayout;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Services\Variables\VariableStore;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Variables\VariableReference;
use App\Support\Variables\VariableReferences;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Applies a ProjectBlueprint to one environment (docs/17 §1), in one
 * transaction and only through the Actions a user would use by hand:
 * AddService (slugs, generated domains, database credentials, plan
 * previews), UpdateServiceSettings, AddServiceVolume, CreateConnection,
 * CreateVariable (generators evaluated once, here) and AddDomain. Services
 * get the slug their name gives them (or the next free one), and every
 * `${{key.…}}` reference is rewritten to it. Staged like any other change;
 * the caller decides whether to deploy.
 */
final readonly class ApplyBlueprint
{
    private const int COLUMN = 340;

    private const int ROW = 190;

    public function __construct(
        private AddService $addService,
        private UpdateServiceSettings $updateSettings,
        private AddServiceVolume $addVolume,
        private CreateConnection $createConnection,
        private CreateVariable $createVariable,
        private AddDomain $addDomain,
        private VariableStore $variables,
    ) {}

    /**
     * @param  string|null  $repository  picker repository id for app services
     * @return array<string, Service> blueprint key => created service
     *
     * @throws DomainRuleViolation
     */
    public function handle(Project $project, Environment $environment, ProjectBlueprint $blueprint, ?string $repository, ?string $branch, User $user, AuditActor $actor): array
    {
        $errors = BlueprintValidator::errors($blueprint);

        if ($errors !== []) {
            throw DomainRuleViolation::on('blueprint', $errors[0]);
        }

        if ($repository === null && array_any($blueprint->services, static fn (BlueprintService $service): bool => $service->kind === ServiceKind::App)) {
            throw DomainRuleViolation::on('repository', 'Pick the repository to build from.');
        }

        return DB::transaction(function () use ($project, $environment, $blueprint, $repository, $branch, $user, $actor): array {
            $positions = $this->positions($environment, $blueprint);
            $services = [];
            $order = [ServiceKind::Database, ServiceKind::App, ServiceKind::Image, ServiceKind::Worker];

            foreach ($order as $kind) {
                foreach ($blueprint->services as $service) {
                    if ($service->kind !== $kind) {
                        continue;
                    }

                    $services[$service->key] = $this->create($project, $environment, $service, $services, $repository, $branch, $positions[$service->key] ?? null, $user, $actor);
                }
            }

            /** @var array<string, string> $slugs */
            $slugs = array_map(static fn (Service $service): string => $service->slug, $services);

            foreach ($blueprint->services as $service) {
                $this->configure($environment, $service, $services[$service->key], $actor);
            }

            foreach ($blueprint->sharedVariables as $variable) {
                $this->variable($project, null, $environment, $variable, $slugs, $user, $actor, 'sharedVariables');
            }

            foreach ($blueprint->services as $service) {
                foreach ($service->connections as $connection) {
                    $this->createConnection->handle($services[$service->key], $services[$connection->to], ConnectionMode::Private, $connection->style, $user, $actor);
                }

                $injected = $this->injected($project, $services[$service->key]);

                foreach ($service->variables as $variable) {
                    $this->variable($project, $services[$service->key], $environment, $variable, $slugs, $user, $actor, 'services.'.$service->key, $injected);
                }
            }

            return $services;
        });
    }

    /**
     * @param  array<string, Service>  $created
     * @param  array{x: int, y: int}|null  $position
     */
    private function create(Project $project, Environment $environment, BlueprintService $service, array $created, ?string $repository, ?string $branch, ?array $position, User $user, AuditActor $actor): Service
    {
        try {
            $model = $this->addService->handle($project, $environment, AddServiceData::from([
                'name' => $service->name,
                'kind' => $service->kind,
                'repository' => $service->kind === ServiceKind::App ? $repository : null,
                'branch' => $service->kind === ServiceKind::App ? $branch : null,
                'rootDirectory' => $service->kind === ServiceKind::App && $service->build instanceof BlueprintBuild && $service->build->context !== '' ? $service->build->context : null,
                'image' => $service->image,
                'port' => in_array($service->kind, [ServiceKind::App, ServiceKind::Image], true) ? $service->port : null,
                'engine' => $service->database?->engine,
                'version' => $service->database?->version,
                'parentServiceId' => $service->parent !== null ? ($created[$service->parent]->public_id ?? null) : null,
                'startCommand' => $service->kind === ServiceKind::Worker ? $service->startCommand : null,
                'position' => $position,
                'connectTo' => null,
            ]), $user, $actor);
        } catch (DomainRuleViolation $e) {
            throw DomainRuleViolation::on('services.'.$service->key, "{$service->name}: {$e->getMessage()}");
        }

        if ($service->logo !== null && in_array($service->logo, ServiceGlyph::KNOWN, true)) {
            $model->forceFill(['icon' => $service->logo])->save();
        }

        return $model;
    }

    private function configure(Environment $environment, BlueprintService $service, Service $model, AuditActor $actor): void
    {
        if ($service->kind === ServiceKind::Database) {
            return;
        }

        $instance = ServiceInstance::query()
            ->where('service_id', $model->id)
            ->where('environment_id', $environment->id)
            ->firstOrFail();
        $deploy = $instance->deploy;

        $settings = new UpdateServiceSettingsData(
            build: $service->build?->dockerfile !== null
                ? new BuildSettings(builder: 'dockerfile', dockerfilePath: $service->build->dockerfile)
                : new Optional,
            deploy: new DeploySettings(
                port: $service->port ?? $deploy->port,
                startCommand: $service->startCommand ?? $deploy->startCommand,
                releaseCommand: $deploy->releaseCommand,
                seedCommand: $deploy->seedCommand,
                healthcheck: $service->healthcheck ?? $deploy->healthcheck,
                drainSeconds: $deploy->drainSeconds,
                entrypoint: $service->entrypoint,
                capabilities: $service->capabilities,
            ),
            scaling: $service->scaling instanceof BlueprintScaling && ! $service->scaling->isEmpty()
                ? new ScalingSettings(
                    replicas: $service->scaling->replicas ?? $instance->scaling->replicas,
                    cpus: $service->scaling->cpus ?? $instance->scaling->cpus,
                    memoryMb: $service->scaling->memoryMb ?? $instance->scaling->memoryMb,
                )
                : new Optional,
        );

        try {
            $this->updateSettings->handle($instance, $settings);

            foreach ($service->volumes as $path) {
                $this->addVolume->handle($instance, new AddVolumeData($path), $actor);
            }

            // Private services keep only their slug: no generated address.
            if (! $service->public && $service->kind === ServiceKind::Image) {
                Domain::query()->where('service_instance_id', $instance->id)->where('kind', DomainKind::Generated->value)->delete();
            }

            if ($service->domain !== null && $service->domain !== '') {
                $this->addDomain->handle($instance, $service->domain, $actor);
            }
        } catch (DomainRuleViolation $e) {
            throw DomainRuleViolation::on('services.'.$service->key, "{$service->name}: {$e->getMessage()}");
        }
    }

    /**
    /**
     * What the service's connections inject (key => value), so a blueprint
     * variable saying the same isn't stored twice.
     *
     * @return array<string, string>
     */
    private function injected(Project $project, Service $service): array
    {
        $injected = [];

        foreach (Variable::query()->where('service_id', $service->id)->whereNotNull('connection_id')->get() as $variable) {
            $injected[$variable->key] = $this->variables->plaintext($project, $variable);
        }

        return $injected;
    }

    /**
     * @param  array<string, string>  $slugs  blueprint key => slug
     * @param  array<string, string>  $injected  connection-owned variables of the service
     */
    private function variable(Project $project, ?Service $service, Environment $environment, BlueprintVariable $variable, array $slugs, User $user, AuditActor $actor, string $field, array $injected = []): void
    {
        $value = VariableReferences::replace($variable->value, static function (VariableReference $reference) use ($slugs): ?string {
            if ($reference->scope === null || $reference->scope === 'shared' || ! isset($slugs[$reference->scope]) || $slugs[$reference->scope] === $reference->scope) {
                return null;
            }

            return '${{'.$slugs[$reference->scope].'.'.$reference->key.'}}';
        });

        if (($injected[$variable->key] ?? null) === $value) {
            return;
        }

        // Browser-inlined keys can never be secrets (docs/06 §3.3).
        $type = $variable->secret && ! VariableExposure::isBrowserInlined($variable->key) ? VariableType::Secret : VariableType::Config;

        try {
            $this->createVariable->handle($project, $service, new VariableInput(
                key: $variable->key,
                value: $value,
                type: $type,
                targets: [VariableTargetData::environment($environment->public_id)],
            ), $user, $actor);
        } catch (DomainRuleViolation $e) {
            throw DomainRuleViolation::on($field, "{$variable->key}: {$e->getMessage()}");
        }
    }

    /**
     * Public → App → Data columns, to the right of what the canvas already has.
     *
     * @return array<string, array{x: int, y: int}>
     */
    private function positions(Environment $environment, ProjectBlueprint $blueprint): array
    {
        $layout = CanvasLayout::query()->where('environment_id', $environment->id)->first();
        $existing = $layout instanceof CanvasLayout ? $layout->positions : [];
        $offset = $existing === [] ? 0 : (int) max(array_map(static fn (array $position): float => (float) $position['x'], $existing)) + self::COLUMN + 80;
        $columns = ['public' => [], 'app' => [], 'data' => []];

        foreach ($blueprint->services as $service) {
            $columns[match (true) {
                $service->kind === ServiceKind::Database => 'data',
                $service->public => 'public',
                default => 'app',
            }][] = $service->key;
        }

        $positions = [];
        $column = 0;

        foreach ($columns as $keys) {
            if ($keys === []) {
                continue;
            }

            foreach ($keys as $row => $key) {
                $positions[$key] = ['x' => $offset + $column * self::COLUMN, 'y' => $row * self::ROW];
            }

            $column++;
        }

        return $positions;
    }
}
