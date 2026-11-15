<?php

declare(strict_types=1);

namespace App\Services\Variables;

use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\Project;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\Variables\ReferenceError;
use App\Support\Variables\SecretTaint;

/**
 * Save-time check (docs/06 §3.3, docs/09 R12): after a variable change, no
 * browser-inlined variable (NEXT_PUBLIC_*, VITE_*, …) of any environment of
 * the project may embed a secret through `${{…}}` references, directly or
 * transitively. Only problems that involve the changed keys are reported, so
 * an unrelated older problem doesn't block every edit (deploys still block on
 * all of them, SpecVariableResolver). VariableStore::write() runs it before
 * its transaction commits, so a refusal rolls the change back.
 *
 * It also enforces "production secrets must differ from preview" for the
 * changed keys when the project has it on (ProductionSecretPolicy).
 */
final readonly class SecretExposureGuard
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private ProductionSecretPolicy $policy,
        private SpecVariableResolver $resolver,
    ) {}

    /**
     * @param  list<string>  $changedKeys
     *
     * @throws DomainRuleViolation
     */
    public function assertNoPublicSecrets(Project $project, array $changedKeys, string $field = 'value'): void
    {
        if ($changedKeys === []) {
            return;
        }

        $this->policy->assert($project, array_values(array_unique($changedKeys)), $field);

        $changed = array_flip($changedKeys);
        $environments = Environment::query()->where('project_id', $project->id)->orderBy('id')->get();

        foreach ($environments as $environment) {
            $environment->setRelation('project', $project);
            $taint = $this->resolver->taint($project, $this->currentSpec->get($environment));

            foreach ($taint->publicExposures() as $problem) {
                if ($this->involves($taint, $problem, $changed)) {
                    throw DomainRuleViolation::on($field, $problem->message().'.');
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $changed
     */
    private function involves(SecretTaint $taint, ReferenceError $problem, array $changed): bool
    {
        foreach ($taint->closure($problem->scope, $problem->key) as $name) {
            $key = substr($name, (int) strrpos($name, '.') + 1);

            if (isset($changed[$key])) {
                return true;
            }
        }

        return false;
    }
}
