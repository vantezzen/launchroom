<?php

declare(strict_types=1);

namespace App\Services\Variables;

use App\Data\Variables\VariableTargetData;
use App\Exceptions\DomainRuleViolation;
use App\Models\Project;
use App\Models\Variable;

/**
 * Turns target input (environment public ids) into rows, scoped to the
 * project, and detects overlapping variables (same key, same service, a
 * shared target), which would make the value ambiguous.
 */
final class VariableTargets
{
    /**
     * @param  list<VariableTargetData>  $targets
     * @return list<array{environment_id: int|null, all_previews: bool, branch_pattern: string|null}>
     */
    public function resolve(Project $project, array $targets): array
    {
        if ($targets === []) {
            throw DomainRuleViolation::on('targets', 'Choose at least one environment.');
        }

        $publicIds = array_values(array_filter(array_map(static fn (VariableTargetData $target): ?string => $target->environmentId, $targets)));

        /** @var array<string, int> $environments */
        $environments = $project->environments()->whereIn('public_id', $publicIds)->pluck('id', 'public_id')->all();

        $rows = [];

        foreach ($targets as $target) {
            if (! $target->isValidShape()) {
                throw DomainRuleViolation::on('targets', 'A target is either one environment or all previews (optionally with a branch pattern).');
            }

            if ($target->environmentId !== null && ! isset($environments[$target->environmentId])) {
                throw DomainRuleViolation::on('targets', 'That environment does not belong to this project.');
            }

            $row = [
                'environment_id' => $target->environmentId === null ? null : $environments[$target->environmentId],
                'all_previews' => $target->allPreviews,
                'branch_pattern' => $target->branchPattern,
            ];

            if (! in_array($row, $rows, true)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{environment_id: int|null, all_previews: bool, branch_pattern: string|null}>  $targets
     */
    public function assertNoOverlap(Project $project, ?int $serviceId, string $key, array $targets, ?int $ignoreVariableId = null): void
    {
        $existing = Variable::query()
            ->where('project_id', $project->id)
            ->where('service_id', $serviceId)
            ->whereNull('connection_id')
            ->where('key', $key)
            ->when($ignoreVariableId !== null, static fn ($query) => $query->whereKeyNot($ignoreVariableId))
            ->with('targets')
            ->get();

        foreach ($existing as $variable) {
            foreach ($variable->targets as $target) {
                foreach ($targets as $row) {
                    $same = $row['environment_id'] !== null
                        ? $target->environment_id === $row['environment_id']
                        : $target->all_previews && $target->branch_pattern === $row['branch_pattern'];

                    if ($same) {
                        throw DomainRuleViolation::conflict("{$key} is already defined for one of these environments. Edit that variable or change its targets.");
                    }
                }
            }
        }
    }
}
