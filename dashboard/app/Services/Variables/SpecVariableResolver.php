<?php

declare(strict_types=1);

namespace App\Services\Variables;

use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\VariableType;
use App\Exceptions\UnresolvedReferences;
use App\Models\Environment;
use App\Models\Project;
use App\Services\Crypto\ProjectKeyring;
use App\Support\Variables\ResolvedEnvironment;
use App\Support\Variables\SecretTaint;
use App\Support\Variables\SystemVariables;
use App\Support\Variables\VariableResolver;

/**
 * Computes the final env of services from an EnvironmentSpec (current intent
 * or a revision), environment-relative, with the precedence of docs/06 §3.1:
 *
 *     system < shared (opted-in) < connection-injected < environment-targeted < branch override
 *
 * Workers and crons also get the variables their app's connections inject.
 *
 * Shared variables a service didn't opt into are reachable only through
 * `${{shared.KEY}}`. Values are decrypted in memory only. Browser-inlined
 * variables that embed a secret block resolution like broken references.
 */
final readonly class SpecVariableResolver
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * Every enabled service's env, or every broken reference at once.
     *
     * @return array<string, ResolvedEnvironment> keyed by service public id
     *
     * @throws UnresolvedReferences
     */
    public function resolveAll(Project $project, EnvironmentSpec $spec): array
    {
        $enabled = array_values(array_map(
            static fn (ServiceSpec $service): string => $service->slug,
            array_filter($spec->services, static fn (ServiceSpec $service): bool => $service->enabled),
        ));
        $bySlug = new VariableResolver($this->scopes($project, $spec))->resolveAll($enabled);
        $this->assertNoPublicSecrets($project, $spec, $enabled);
        $resolved = [];

        foreach ($spec->services as $id => $service) {
            if ($service->enabled && isset($bySlug[$service->slug])) {
                $resolved[$id] = $bySlug[$service->slug];
            }
        }

        return $resolved;
    }

    /**
     * @throws UnresolvedReferences
     */
    public function resolve(Project $project, EnvironmentSpec $spec, ServiceSpec $service): ResolvedEnvironment
    {
        $resolved = new VariableResolver($this->scopes($project, $spec))->resolve($service->slug);
        $this->assertNoPublicSecrets($project, $spec, [$service->slug]);

        return $resolved;
    }

    /**
     * Browser-inlined variables never carry a secret, also not through a
     * reference (docs/06 §3.3, docs/09 R12): such a deploy is blocked like a
     * broken reference.
     *
     * @param  list<string>  $slugs
     *
     * @throws UnresolvedReferences
     */
    public function assertNoPublicSecrets(Project $project, EnvironmentSpec $spec, array $slugs): void
    {
        $problems = $this->taint($project, $spec)->publicExposures($slugs);

        if ($problems !== []) {
            throw new UnresolvedReferences($problems);
        }
    }

    /**
     * Secret taint over the same scopes resolution uses, shared preview
     * services included.
     */
    public function taint(Project $project, EnvironmentSpec $spec): SecretTaint
    {
        return SecretTaint::fromSpec($spec, array_filter(array_map(
            fn (string $baseEnvironmentId): ?EnvironmentSpec => $this->baseSpec($project, $baseEnvironmentId),
            $this->sharedServices($project, $spec),
        )));
    }

    /**
     * The merged raw (unresolved) values of every scope after precedence,
     * each with whether it is a secret: for editor previews that expand
     * references while masking secrets (App\Support\Variables\ReferencePreview).
     * Plaintext lives in memory only; callers never serialize it.
     *
     * @return array<string, array<string, array{value: string, secret: bool}>>
     */
    public function annotatedScopes(Project $project, EnvironmentSpec $spec): array
    {
        $annotate = fn (VariableSpec $variable): array => ['value' => $this->decrypt($project, $variable), 'secret' => $variable->type === VariableType::Secret];
        $scopes = [VariableResolver::SHARED => []];

        foreach ($spec->sharedVariables as $variable) {
            $scopes[VariableResolver::SHARED][$variable->key] = $annotate($variable);
        }

        foreach ($spec->services as $service) {
            $values = array_map(
                static fn (string $value): array => ['value' => $value, 'secret' => false],
                SystemVariables::for($spec->environment, $service, $service->deploy->port, $service->parentId === null ? null : $spec->service($service->parentId)),
            );

            foreach ($spec->variablesInPrecedence($service) as $variable) {
                $values[$variable->key] = $annotate($variable);
            }

            $scopes[$service->slug] = $values;
        }

        foreach ($this->sharedServices($project, $spec) as $serviceId => $baseEnvironmentId) {
            $shared = $spec->service($serviceId);
            $baseSpec = $shared instanceof ServiceSpec ? $this->baseSpec($project, $baseEnvironmentId) : null;
            $base = $baseSpec instanceof EnvironmentSpec ? $this->annotatedScopes($project, $baseSpec) : null;

            if ($shared instanceof ServiceSpec && $base !== null && isset($base[$shared->slug])) {
                $scopes[$shared->slug] = $base[$shared->slug];
            }
        }

        return $scopes;
    }

    /**
     * Previews sharing a base environment's database: references to it
     * resolve to the base instance (docs/06 §5 "share base instance"). Never
     * into an environment that withholds secrets now (fork previews, docs/09
     * R20), whatever the revision recorded; an unknown environment fails
     * closed.
     *
     * @return array<string, string> service public id => base environment public id
     */
    private function sharedServices(Project $project, EnvironmentSpec $spec): array
    {
        if ($spec->sharedServices === []) {
            return [];
        }

        $environment = Environment::query()->where('project_id', $project->id)->where('public_id', $spec->environment->id)->first(['id', 'secrets_withheld']);

        return ! $environment instanceof Environment || $environment->secrets_withheld ? [] : $spec->sharedServices;
    }

    /**
     * A base environment's last applied spec (for shared preview services);
     * null when it was never deployed or itself shares services.
     */
    private function baseSpec(Project $project, string $environmentId): ?EnvironmentSpec
    {
        $environment = Environment::query()
            ->where('project_id', $project->id)
            ->where('public_id', $environmentId)
            ->with('lastAppliedRevision')
            ->first();
        $spec = $environment?->lastAppliedRevision?->spec;

        return $spec === null || $spec->sharedServices !== [] ? null : $spec;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function scopes(Project $project, EnvironmentSpec $spec): array
    {
        return array_map(
            static fn (array $values): array => array_map(static fn (array $entry): string => $entry['value'], $values),
            $this->annotatedScopes($project, $spec),
        );
    }

    private function decrypt(Project $project, VariableSpec $variable): string
    {
        if ($variable->withheld) {
            return '';
        }

        return $this->keyring->decryptVariable($project, $variable->id, $variable->version, $variable->ciphertext);
    }
}
