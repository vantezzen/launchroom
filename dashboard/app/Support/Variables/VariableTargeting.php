<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Variables\VariableTargetRow;
use App\Enums\EnvironmentKind;
use App\Enums\VariableLayer;

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
     * @param  list<VariableTargetRow>  $targets
     */
    public static function layer(array $targets, int $environmentId, EnvironmentKind $kind, ?string $branch): ?VariableLayer
    {
        $layer = null;

        foreach ($targets as $target) {
            if ($target->environmentId === $environmentId) {
                $layer ??= VariableLayer::Environment;
            }

            if ($kind !== EnvironmentKind::Preview || ! $target->allPreviews) {
                continue;
            }

            if ($target->branchPattern === null) {
                $layer ??= VariableLayer::Environment;
            } elseif ($branch !== null && fnmatch($target->branchPattern, $branch)) {
                $layer = VariableLayer::Branch;
            }
        }

        return $layer;
    }
}
