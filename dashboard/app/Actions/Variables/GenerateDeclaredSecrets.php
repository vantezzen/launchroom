<?php

declare(strict_types=1);

namespace App\Actions\Variables;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Protocol\V1\GeneratedSecret;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Data\Variables\VariableTargetRow;
use App\Enums\AuditAction;
use App\Enums\VariableType;
use App\Events\CanvasChanged;
use App\Exceptions\UnresolvedReferences;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Variable;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Deployments\DeploymentSnapshots;
use App\Services\Environments\EnvironmentRevisions;
use App\Services\Variables\SpecVariableResolver;
use App\Services\Variables\VariableStore;

/**
 * Creates the runtime secrets a deployment's build summary declares
 * (`summary.generatedSecrets`, D86: Laravel's APP_KEY) that its service
 * doesn't have in its environment, once the image is known and before
 * anything runs with the env.
 *
 * - Each one becomes an ordinary secret variable of the service, targeted at
 *   the deployment's environment, with its generator as the value
 *   (`${{ secret(32) }}`), so copies to other environments get a fresh one.
 *   A worker or cron whose app has the key gets a reference to the app's
 *   (`${{api.APP_KEY}}`): both run the same code and must share it.
 * - A variable the service already sees wins (its own, shared, connection
 *   or branch rows, in the deployment's env or in the current intent); a
 *   secret withheld from a fork preview counts as missing.
 * - The rows are also written into a new applied revision on top of the
 *   last one (like domains, ApplyDomainRoutes), so they don't show up as
 *   staged changes and Discard can't remove them.
 * - The deployment's env snapshot gets the value the service has in the
 *   last applied revision, also one another deployment created meanwhile.
 * - The project row lock (the variable write path's) plus re-checking
 *   under it keep concurrent deploys from creating two values.
 */
final readonly class GenerateDeclaredSecrets
{
    public function __construct(
        private VariableStore $store,
        private CurrentEnvironmentSpec $current,
        private SpecVariableResolver $resolver,
        private ProjectKeyring $keyring,
        private DeploymentSnapshots $snapshots,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return list<string> the keys added to the deployment's env
     */
    public function handle(Deployment $deployment): array
    {
        $declared = $deployment->plan?->summary->generatedSecrets ?? [];
        $snapshot = $deployment->env_snapshot;

        if ($declared === [] || $snapshot === null) {
            return [];
        }

        $deployment->loadMissing(['environment.project', 'serviceInstance.service']);
        $project = $deployment->environment->project;
        $env = $this->keyring->decryptSnapshot($project, $deployment->public_id, $snapshot);
        $missing = array_values(array_filter($declared, static fn (GeneratedSecret $secret): bool => ($env[$secret->name] ?? '') === ''));

        if ($missing === []) {
            return [];
        }

        return $this->store->write($project, function () use ($project, $deployment, $missing, $env): array {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $environment = Environment::query()->whereKey($deployment->environment_id)->with('lastAppliedRevision')->lockForUpdate()->firstOrFail();
            $service = $deployment->serviceInstance->service;
            $intent = $this->current->get($environment);
            $own = $intent->service($service->public_id);

            if (! $own instanceof ServiceSpec) {
                return [];
            }

            $parent = $own->parentId === null ? null : $intent->service($own->parentId);
            /** @var array<string, Variable> $created */
            $created = [];

            foreach ($missing as $secret) {
                if ($this->sees($own, $intent, $secret->name)) {
                    continue;
                }

                if ($own->kind->requiresParent()) {
                    if (! $parent instanceof ServiceSpec || ! $this->sees($parent, $intent, $secret->name)) {
                        continue;
                    }

                    $value = '${{'.$parent->slug.'.'.$secret->name.'}}';
                } else {
                    $value = $secret->expression();
                }

                $variable = $this->store->create($project, $service, $secret->name, $value, VariableType::Secret, [VariableTargetRow::environment($environment->id)]);
                $created[$secret->name] = $variable;

                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::VariableCreated,
                    actor: AuditActor::system(),
                    teamId: $project->team_id,
                    subjectType: 'variable',
                    subjectId: $variable->public_id,
                    meta: ['key' => $variable->key, 'type' => $variable->type->value, 'service' => $service->public_id, 'generated' => true, 'deployment' => $deployment->public_id],
                ));
            }

            $applied = $created === []
                ? $environment->lastAppliedRevision?->spec
                : $this->applyToRevision($environment, $service->public_id, $deployment->serviceInstance->public_id, array_values($created));
            $added = [];

            // Also keys another deployment created meanwhile (its row is
            // applied, this snapshot predates it): every deployment runs
            // with the one value.
            foreach ($missing as $secret) {
                $value = $this->valueOf($project, $applied, $service->public_id, $secret->name, $created[$secret->name] ?? null);

                if ($value !== null && $value !== '') {
                    $env[$secret->name] = $value;
                    $added[] = $secret->name;
                }
            }

            if ($added !== []) {
                $this->snapshots->store($project, $deployment, $env);
            }

            if ($created !== []) {
                CanvasChanged::forProject($project);
            }

            return $added;
        });
    }

    /**
     * Whether $service has $key in its environment: any row (own, shared,
     * connection, branch) that isn't a secret withheld from a fork preview.
     */
    private function sees(ServiceSpec $service, EnvironmentSpec $spec, string $key): bool
    {
        return array_any($spec->variablesInPrecedence($service), static fn (VariableSpec $variable): bool => $variable->key === $key && ! $variable->withheld);
    }

    /**
     * Adds the new rows to the service in a new applied revision on top of
     * the last one. Returns that revision's spec, or null when the service
     * isn't part of it (the rows then stay staged for the next deploy).
     *
     * @param  list<Variable>  $created
     */
    private function applyToRevision(Environment $environment, string $serviceId, string $instanceId, array $created): ?EnvironmentSpec
    {
        $base = $environment->lastAppliedRevision?->spec;
        $applied = $base?->service($serviceId);

        if (! $base instanceof EnvironmentSpec || ! $applied instanceof ServiceSpec || $applied->instanceId !== $instanceId) {
            return null;
        }

        $intent = $this->current->get($environment)->service($serviceId);
        $variables = $applied->variables;

        foreach ($created as $variable) {
            $spec = $intent?->variables[$variable->public_id] ?? null;

            if ($spec instanceof VariableSpec) {
                $variables[$variable->public_id] = $spec;
            }
        }

        $service = clone $applied;
        $service->variables = $variables;
        $spec = clone $base;
        $spec->services = [...$base->services, $serviceId => $service];
        $keys = implode(', ', array_map(static fn (Variable $variable): string => $variable->key, $created));

        return EnvironmentRevisions::apply($environment, $spec, "Generated {$keys} for {$applied->name}", null)->spec;
    }

    /**
     * The value of $key for the service in the applied revision, else (the
     * service isn't part of it) the plaintext of a row just generated.
     */
    private function valueOf(Project $project, ?EnvironmentSpec $applied, string $serviceId, string $key, ?Variable $created): ?string
    {
        $service = $applied?->service($serviceId);

        if ($applied instanceof EnvironmentSpec && $service instanceof ServiceSpec) {
            try {
                return $this->resolver->resolve($project, $applied, $service)->get($key);
            } catch (UnresolvedReferences) {
                return null;
            }
        }

        return $created instanceof Variable && $created->references === [] ? $this->store->plaintext($project, $created) : null;
    }
}
