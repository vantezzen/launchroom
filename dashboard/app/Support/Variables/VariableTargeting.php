<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Specs\VariableSpec;
use App\Enums\EnvironmentKind;

/**
 * Whether a variable row applies to an environment, and in which precedence
 * layer (docs/06 §3.1): rows targeted at the environment (or "all previews"
 * without a branch pattern, for previews) form the environment layer; "all
 * previews" rows whose branch pattern matches a preview's branch form the
 * branch layer, which wins.
 */
final class VariableTargeting
{
    /**
     * @param  list<array{environment_id: int|null, all_previews: bool, branch_pattern: string|null}>  $targets
     * @return VariableSpec::LAYER_*|null
     */
    public static function layer(array $targets, int $environmentId, EnvironmentKind $kind, ?string $branch): ?string
    {
        $layer = null;

        foreach ($targets as $target) {
            if ($target['environment_id'] === $environmentId) {
                $layer ??= VariableSpec::LAYER_ENVIRONMENT;
            }

            if ($kind !== EnvironmentKind::Preview || ! $target['all_previews']) {
                continue;
            }

            if ($target['branch_pattern'] === null) {
                $layer ??= VariableSpec::LAYER_ENVIRONMENT;
            } elseif ($branch !== null && fnmatch($target['branch_pattern'], $branch)) {
                $layer = VariableSpec::LAYER_BRANCH;
            }
        }

        return $layer;
    }
}
