<?php

declare(strict_types=1);

namespace App\Services\Variables;

use App\Enums\EnvironmentKind;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Services\Crypto\ProjectKeyring;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * "Production secrets must differ from preview" (docs/06 §3.1), on when the
 * team or the project enables it. A secret that applies to production must
 * not have the same value as the same key of the same service (or the same
 * shared key) where it applies to previews: rows targeted at "All previews"
 * (any branch pattern) and rows of preview environments. A row targeted at
 * both production and previews always violates it.
 *
 * Enforced when variables are saved (SecretExposureGuard), on every deploy
 * of production and previews (Deploy, push and tag deploys, preview
 * deploys), by Compare & promote, and when a preview's variables are copied
 * from its base: a secret equal to production's is not copied, whatever
 * environment it comes from (e.g. staging copied from production).
 */
final readonly class ProductionSecretPolicy
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    public function enabled(Project $project): bool
    {
        $project->loadMissing('team');

        return $project->settings->productionSecretsMustDiffer || $project->team->settings->productionSecretsMustDiffer;
    }

    /**
     * @param  list<string>|null  $keys  only check these keys (null: all)
     *
     * @throws DomainRuleViolation
     */
    public function assert(Project $project, ?array $keys = null, string $field = 'value'): void
    {
        $violations = $this->violations($project, $keys);

        if ($violations !== []) {
            throw DomainRuleViolation::on($field, implode(' ', $violations));
        }
    }

    /**
     * MACs of production's secret values per `service id|key` (empty when
     * the policy is off): to keep them out of previews.
     *
     * @return array<string, list<string>>
     */
    public function productionSecretMacs(Project $project): array
    {
        if (! $this->enabled($project)) {
            return [];
        }

        $production = Environment::query()->where('project_id', $project->id)->where('kind', EnvironmentKind::Production->value)->value('id');

        if ($production === null) {
            return [];
        }

        $macs = [];
        $rows = Variable::query()
            ->where('project_id', $project->id)
            ->where('type', VariableType::Secret->value)
            ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $production))
            ->get();

        foreach ($rows as $row) {
            $macs[($row->service_id ?? 0).'|'.$row->key][] = $this->macOf($project, $row);
        }

        return $macs;
    }

    /**
     * Whether $row has the value of production's secret of the same key and
     * service ($macs from {@see productionSecretMacs()}).
     *
     * @param  array<string, list<string>>  $macs
     */
    public function equalsProduction(Project $project, Variable $row, array $macs): bool
    {
        $known = $macs[($row->service_id ?? 0).'|'.$row->key] ?? [];

        return $known !== [] && in_array($this->macOf($project, $row), $known, true);
    }

    private function macOf(Project $project, Variable $row): string
    {
        return $this->keyring->mac($project, 'secret-policy', $this->keyring->decryptVariable($project, $row->public_id, $row->version, $row->value));
    }

    /**
     * @param  list<string>|null  $keys
     * @return list<string> one message per offending key
     */
    public function violations(Project $project, ?array $keys = null): array
    {
        if (! $this->enabled($project) || $keys === []) {
            return [];
        }

        $environments = Environment::query()->where('project_id', $project->id)->get(['id', 'kind']);
        $production = $environments->firstWhere('kind', EnvironmentKind::Production)?->id;
        $previews = $environments->where('kind', EnvironmentKind::Preview)->pluck('id')->all();

        if ($production === null) {
            return [];
        }

        $rows = Variable::query()
            ->where('project_id', $project->id)
            ->whereNull('connection_id')
            ->when($keys !== null, static fn ($query) => $query->whereIn('key', $keys))
            ->with(['targets', 'service:id,slug'])
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $groups[($row->service_id ?? 0).'|'.$row->key][] = $row;
        }

        $messages = [];

        foreach ($groups as $group) {
            $inProduction = array_values(array_filter($group, static fn (Variable $row): bool => $row->type === VariableType::Secret && $row->targets->contains(static fn (VariableTarget $target): bool => $target->environment_id === $production)));
            $inPreviews = array_values(array_filter($group, static fn (Variable $row): bool => $row->targets->contains(static fn (VariableTarget $target): bool => $target->all_previews || in_array($target->environment_id, $previews, true))));

            if ($inProduction === [] || $inPreviews === []) {
                continue;
            }

            $previewValues = [];

            foreach ($inPreviews as $row) {
                $previewValues[$row->id] = $this->keyring->mac($project, 'secret-policy', $this->keyring->decryptVariable($project, $row->public_id, $row->version, $row->value));
            }

            foreach ($inProduction as $row) {
                $value = $this->keyring->mac($project, 'secret-policy', $this->keyring->decryptVariable($project, $row->public_id, $row->version, $row->value));

                if (in_array($value, $previewValues, true)) {
                    $scope = $row->service->slug ?? 'shared';
                    $messages[] = "{$row->key} ({$scope}) has the same value in production and in previews, but production secrets must differ from preview in this project. Give previews their own value.";

                    break;
                }
            }
        }

        return $messages;
    }
}
