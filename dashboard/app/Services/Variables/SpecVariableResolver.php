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
        self::assertNoPublicSecrets($spec, $enabled);
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
        self::assertNoPublicSecrets($spec, [$service->slug]);

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
    public static function assertNoPublicSecrets(EnvironmentSpec $spec, array $slugs): void
    {
        $problems = SecretTaint::fromSpec($spec)->publicExposures($slugs);

        if ($problems !== []) {
            throw new UnresolvedReferences($problems);
        }
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
        $shared = [];

        foreach ($spec->sharedVariables as $variable) {
            $shared[$variable->key] = ['value' => $this->decrypt($project, $variable), 'secret' => $variable->type === VariableType::Secret];
        }

        $scopes = [VariableResolver::SHARED => $shared];

        foreach ($spec->services as $service) {
            $values = array_map(
                static fn (string $value): array => ['value' => $value, 'secret' => false],
                SystemVariables::for($spec->environment, $service, $service->deploy->port),
            );

            foreach ($service->sharedVariableIds as $variableId) {
                $variable = $spec->sharedVariables[$variableId] ?? null;

                if ($variable !== null) {
                    $values[$variable->key] = $shared[$variable->key];
                }
            }

            foreach ($service->connectionVariables as $variable) {
                $values[$variable->key] = ['value' => $this->decrypt($project, $variable), 'secret' => $variable->type === VariableType::Secret];
            }

            foreach ([VariableSpec::LAYER_ENVIRONMENT, VariableSpec::LAYER_BRANCH] as $layer) {
                foreach ($service->variables as $variable) {
                    if ($variable->layer === $layer) {
                        $values[$variable->key] = ['value' => $this->decrypt($project, $variable), 'secret' => $variable->type === VariableType::Secret];
                    }
                }
            }

            $scopes[$service->slug] = $values;
        }

        // Previews sharing a base environment's database: references to it
        // resolve to the base instance (docs/06 §5 "share base instance").
        // Never into an environment that withholds secrets (fork previews,
        // docs/09 R20), also not from a revision recorded before that rule.
        foreach (self::withholdsSecrets($project, $spec) ? [] : $spec->sharedServices as $serviceId => $baseEnvironmentId) {
            $shared = $spec->service($serviceId);
            $base = $shared instanceof ServiceSpec ? $this->baseScopes($project, $baseEnvironmentId) : null;

            if ($shared instanceof ServiceSpec && $base !== null && isset($base[$shared->slug])) {
                $scopes[$shared->slug] = $base[$shared->slug];
            }
        }

        return $scopes;
    }

    /**
     * Whether the spec's environment withholds secrets (fork previews): it
     * may then not resolve anything against a base environment.
     */
    public static function withholdsSecrets(Project $project, EnvironmentSpec $spec): bool
    {
        if ($spec->sharedServices === []) {
            return false;
        }

        $withheld = Environment::query()->where('project_id', $project->id)->where('public_id', $spec->environment->id)->value('secrets_withheld');

        // An unknown environment fails closed.
        return ! in_array($withheld, [false, 0, '0', 'f'], true);
    }

    /**
     * The scopes of a base environment's last applied revision (for shared
     * preview services); null when it was never deployed.
     *
     * @return array<string, array<string, array{value: string, secret: bool}>>|null
     */
    private function baseScopes(Project $project, string $environmentId): ?array
    {
        $environment = Environment::query()
            ->where('project_id', $project->id)
            ->where('public_id', $environmentId)
            ->with('lastAppliedRevision')
            ->first();
        $spec = $environment?->lastAppliedRevision?->spec;

        return $spec === null || $spec->sharedServices !== [] ? null : $this->annotatedScopes($project, $spec);
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
