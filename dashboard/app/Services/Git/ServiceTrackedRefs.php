<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Contracts\Git\TrackedRefs;
use App\Data\Git\TrackedRef;
use App\Data\Services\GitSource;
use App\Enums\EnvironmentKind;
use App\Models\ServiceInstance;

/**
 * The refs the polling fallback watches (docs/06 §2): for every live service
 * instance with a connected Git source (any provider), `refs/heads/<branch>` of its branch override
 * or its environment's trigger branch. Glob triggers (`release/*`) can't be
 * polled as one ref and are left to webhooks; preview environments follow
 * pull requests, not branches, and are skipped.
 */
final class ServiceTrackedRefs implements TrackedRefs
{
    public function all(): iterable
    {
        $seen = [];

        $instances = ServiceInstance::query()
            ->current()
            ->where('enabled', true)
            ->whereHas('service', static fn ($query) => $query->whereRaw("source->>'type' = 'git'")->whereRaw("source->>'installationId' IS NOT NULL"))
            ->with(['service', 'environment'])
            ->orderBy('id')
            ->lazy(200);

        foreach ($instances as $instance) {
            $source = $instance->service->source;
            $environment = $instance->environment;

            if (! $source instanceof GitSource || $source->installationId === null || $environment->kind === EnvironmentKind::Preview) {
                continue;
            }

            $branch = $instance->branch_override ?? $environment->triggers->branch;

            if ($branch === null || $branch === '' || strpbrk($branch, '*?[') !== false) {
                continue;
            }

            $key = "{$source->provider}:{$source->installationId}:{$source->repositoryId}:{$branch}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            yield new TrackedRef($source->installationId, $source->repositoryId, 'refs/heads/'.$branch, $source->kind());
        }
    }
}
