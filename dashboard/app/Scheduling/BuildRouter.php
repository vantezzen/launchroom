<?php

declare(strict_types=1);

namespace App\Scheduling;

/**
 * Chooses where a build runs (docs/04 §5). Pure, unit-tested.
 *
 * - platform: built images are single-platform (no multi-platform
 *   manifests yet), so a build targets one platform: the
 *   current deployment's when its servers can still run it, else the
 *   platform most eligible runtime servers have (ties: the lowest server id),
 *   skipping platforms no build server can build; the scheduler then places
 *   the image only on servers of that platform; a builder counts for its
 *   own platform and for the ones its engine emulates (D53);
 * - server: the sticky server (built the service last: BuildKit mount caches
 *   are local) when it is online, of the platform and has a free slot; else
 *   the least-loaded build server of the platform with a free slot (running /
 *   slots, then running, then lower id); when every one is busy, the sticky
 *   server if it can build the platform, else nobody: the task stays in the
 *   pool and the first server with a free slot leases it. Builders that
 *   build the platform natively come first: emulated builders are only
 *   chosen when no native one is online (emulation is several times slower).
 */
final class BuildRouter
{
    /**
     * @param  array<string, int>  $runtimePlatforms  platform => eligible runtime servers on it (insertion order = lowest server id first)
     * @param  list<BuildCandidate>  $builders
     *
     * @throws BuildRoutingFailed
     */
    public static function platform(array $runtimePlatforms, array $builders, ?string $current = null, string $fallback = 'linux/amd64'): string
    {
        $buildable = [];

        foreach ($builders as $builder) {
            if ($builder->online && $builder->platform !== null) {
                $buildable[$builder->platform] = true;

                foreach ($builder->emulated as $emulated) {
                    $buildable[$emulated] = true;
                }
            }
        }

        // A builder of unknown platform builds for anything (it leases by spec).
        $anything = array_any($builders, static fn (BuildCandidate $b): bool => $b->online && $b->platform === null);
        $canBuild = static fn (string $platform): bool => $anything || isset($buildable[$platform]);

        if ($runtimePlatforms === []) {
            // No runtime server known yet: build for a platform some builder has.
            return $current ?? (isset($buildable[$fallback]) || $buildable === [] ? $fallback : array_key_first($buildable));
        }

        if ($current !== null && isset($runtimePlatforms[$current]) && $canBuild($current)) {
            return $current;
        }

        $order = array_keys($runtimePlatforms);
        $rank = array_flip($order);
        usort($order, static fn (string $a, string $b): int => [$runtimePlatforms[$b], $rank[$a]] <=> [$runtimePlatforms[$a], $rank[$b]]);

        foreach ($order as $platform) {
            if ($canBuild($platform)) {
                return $platform;
            }
        }

        throw BuildRoutingFailed::noBuilderFor(array_keys($runtimePlatforms));
    }

    /**
     * @param  list<BuildCandidate>  $builders
     * @return int|null the server to pin the build to, or null for the pool
     */
    public static function server(array $builders, string $platform, ?int $stickyId = null): ?int
    {
        $eligible = array_values(array_filter($builders, static fn (BuildCandidate $b): bool => $b->online && $b->native($platform)));

        if ($eligible === []) {
            $eligible = array_values(array_filter($builders, static fn (BuildCandidate $b): bool => $b->online && $b->builds($platform)));
        }

        $sticky = null;

        foreach ($eligible as $builder) {
            if ($builder->id === $stickyId) {
                $sticky = $builder;
            }
        }

        if ($sticky instanceof BuildCandidate && $sticky->hasFreeSlot()) {
            return $sticky->id;
        }

        $free = array_values(array_filter($eligible, static fn (BuildCandidate $b): bool => $b->hasFreeSlot()));

        if ($free === []) {
            return $sticky?->id;
        }

        usort($free, static fn (BuildCandidate $a, BuildCandidate $b): int => [$a->load(), $a->running, $a->id] <=> [$b->load(), $b->running, $b->id]);

        return $free[0]->id;
    }
}
