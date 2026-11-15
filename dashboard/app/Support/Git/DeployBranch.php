<?php

declare(strict_types=1);

namespace App\Support\Git;

use App\Data\Services\GitSource;
use App\Models\ServiceInstance;

/**
 * Which branch a service instance deploys (docs/06 §2). Two questions with
 * different answers:
 *
 * - {@see pushPattern()}: which branch pushes deploy it: the branch override,
 *   else the environment's branch glob. Nothing else: a manual-only or
 *   tags-only environment never deploys on a branch push, whatever the
 *   source's own branch is. Webhooks and polling both follow it.
 * - {@see for()}: which branch a manual deploy builds: the override, the
 *   environment's branch, then the source's own branch, skipping patterns
 *   (`release/*` names no single branch). Callers fall back to the provider's
 *   default branch when this is null.
 */
final class DeployBranch
{
    public static function for(ServiceInstance $instance, GitSource $source): ?string
    {
        foreach ([$instance->branch_override, $instance->environment->triggers->branch, $source->branch] as $branch) {
            if (self::isBranch($branch)) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * The branch glob a push must match to deploy the instance, or null when
     * branch pushes never deploy it.
     */
    public static function pushPattern(ServiceInstance $instance): ?string
    {
        $pattern = $instance->branch_override ?? $instance->environment->triggers->branch;

        return $pattern === null || $pattern === '' ? null : $pattern;
    }

    /**
     * @phpstan-assert-if-true non-empty-string $branch
     */
    public static function isBranch(?string $branch): bool
    {
        return $branch !== null && $branch !== '' && strpbrk($branch, '*?[') === false;
    }
}
