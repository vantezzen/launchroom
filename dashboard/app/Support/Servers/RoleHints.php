<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Data\Servers\RoleHintData;
use App\Enums\DeviceKind;
use App\Enums\ServerRole;
use App\Enums\Servers\RoleHintTone;

/**
 * Contextual hints for a server's roles (docs/08 §14, design-system §13.9):
 * short, positive notes from real constraints, shown under the role cards
 * in Add server and on the server page. Pure: the same context always gives
 * the same hints. At most MAX are returned, risks (amber) first.
 *
 * Constraints behind the rules:
 * - an edge receives public traffic directly (docs/05 §4), so at home it
 *   needs a public IP or forwarded ports;
 * - an image is built for one platform, the runtime servers' (docs/04 §5);
 *   no build server for it fails the deploy;
 * - builds next to apps compete for the same CPU and memory;
 * - the registry runs next to the dashboard; every server pulls from it
 *   (docs/05 §6).
 */
final class RoleHints
{
    public const int MAX = 2;

    /** Below either of these a machine is small for builds next to apps. */
    private const int SMALL_MEMORY_MB = 2048;

    private const int SMALL_CPUS = 2;

    /** @var list<DeviceKind> */
    private const array AT_HOME = [
        DeviceKind::Tower, DeviceKind::Minipc, DeviceKind::Compact, DeviceKind::Sbc, DeviceKind::Nas, DeviceKind::Laptop,
    ];

    /**
     * @return list<RoleHintData>
     */
    public static function for(RoleHintContext $context): array
    {
        $hints = array_values(array_filter([
            self::buildArchGap($context),
            self::smallBuilder($context),
            self::edgeAtHome($context),
            self::sleeps($context),
            self::buildArch($context),
            self::sharedBuilder($context),
            self::onlyEdge($context),
            self::registryAtHome($context),
        ]));

        // Stable: risks first, then the order above.
        usort($hints, static fn (RoleHintData $a, RoleHintData $b): int => ($a->tone === RoleHintTone::Warning ? 0 : 1) <=> ($b->tone === RoleHintTone::Warning ? 0 : 1));

        return array_slice($hints, 0, self::MAX);
    }

    /** Apps placed here would fail to deploy: no build server has this architecture. */
    private static function buildArchGap(RoleHintContext $c): ?RoleHintData
    {
        if (! $c->existing || ! $c->has(ServerRole::Runtime) || $c->has(ServerRole::Build) || $c->arch === null
            || $c->otherBuildArchs === [] || in_array($c->arch, $c->otherBuildArchs, true)) {
            return null;
        }

        $arch = self::archLabel($c->arch);

        return new RoleHintData(
            'build-arch-gap',
            RoleHintTone::Warning,
            "No {$arch} build server yet",
            "Images are built for the architecture they run on, so apps placed here need an {$arch} build server. Turning on Build here works too.",
            'servers/roles-and-placement',
        );
    }

    private static function smallBuilder(RoleHintContext $c): ?RoleHintData
    {
        if (! self::buildsNextToApps($c) || ! self::small($c)) {
            return null;
        }

        return new RoleHintData(
            'small-builder',
            RoleHintTone::Warning,
            'Small for builds next to apps',
            'Builds use a lot of CPU and memory. With '.self::size($c).', they can slow down or drop requests. Add a separate build server to keep your apps responsive.',
            'servers/roles-and-placement',
        );
    }

    private static function edgeAtHome(RoleHintContext $c): ?RoleHintData
    {
        if (! $c->has(ServerRole::Edge) || ! self::atHome($c)) {
            return null;
        }

        return new RoleHintData(
            'edge-at-home',
            RoleHintTone::Info,
            'Serving traffic from home needs a public address',
            'Visitors connect to this machine directly, so it needs a public IP or ports 80 and 443 forwarded on your router. Most home setups build or run apps here and let a cloud server take the traffic.',
            'servers/home-machines',
        );
    }

    private static function sleeps(RoleHintContext $c): ?RoleHintData
    {
        $laptop = $c->device === DeviceKind::Laptop || $c->battery;

        if (! $laptop || (! $c->has(ServerRole::Runtime) && ! $c->has(ServerRole::Edge))) {
            return null;
        }

        return new RoleHintData(
            'sleeps',
            RoleHintTone::Info,
            'Apps here stop when it sleeps',
            'A laptop that sleeps or runs out of battery takes its apps offline. It works well as a build server. Run apps and serve traffic on a machine that stays on.',
        );
    }

    /** This server builds for its own architecture only; other runtimes need their own builder. */
    private static function buildArch(RoleHintContext $c): ?RoleHintData
    {
        if (! $c->has(ServerRole::Build) || $c->arch === null) {
            return null;
        }

        $uncovered = array_values(array_filter(
            array_unique($c->otherRuntimeArchs),
            static fn (string $arch): bool => $arch !== $c->arch && ! in_array($arch, $c->otherBuildArchs, true),
        ));

        if ($uncovered === []) {
            return null;
        }

        $here = self::archLabel($c->arch);
        $there = self::archLabel($uncovered[0]);

        return new RoleHintData(
            'build-arch',
            RoleHintTone::Info,
            "Builds here are for {$here} servers",
            "Images are built for the architecture they run on, so your {$there} servers need a build server of their own.",
            'servers/roles-and-placement',
        );
    }

    private static function sharedBuilder(RoleHintContext $c): ?RoleHintData
    {
        // One server doing everything is the default (docs/08 §14), not worth a note.
        if (! self::buildsNextToApps($c) || self::small($c) || ($c->existing && $c->otherServers === 0)) {
            return null;
        }

        $while = $c->has(ServerRole::Edge) ? 'serving traffic' : 'running your apps';

        return new RoleHintData(
            'shared-builder',
            RoleHintTone::Info,
            'Builds share this machine with your apps',
            "Make sure it’s powerful enough to build images while {$while}. Large builds can slow down requests. Add a separate build server to keep them apart.",
            'servers/roles-and-placement',
        );
    }

    private static function onlyEdge(RoleHintContext $c): ?RoleHintData
    {
        if (! $c->existing || ! $c->has(ServerRole::Edge) || $c->otherEdges > 0 || $c->otherServers === 0) {
            return null;
        }

        return new RoleHintData(
            'only-edge',
            RoleHintTone::Info,
            'This is your only edge',
            'All public traffic enters here. To move it, turn on Serve traffic on another server first, then point DNS there.',
            'servers/high-availability-ingress',
        );
    }

    private static function registryAtHome(RoleHintContext $c): ?RoleHintData
    {
        if (! $c->existing || ! $c->has(ServerRole::Registry) || ! self::atHome($c) || $c->otherServers === 0) {
            return null;
        }

        return new RoleHintData(
            'registry-at-home',
            RoleHintTone::Info,
            'Built images are stored here',
            'Your other servers download each new image from this machine, so deploys depend on its upload speed and on it staying online.',
            'servers/home-machines',
        );
    }

    private static function buildsNextToApps(RoleHintContext $c): bool
    {
        return $c->has(ServerRole::Build) && ($c->has(ServerRole::Runtime) || $c->has(ServerRole::Edge));
    }

    private static function atHome(RoleHintContext $c): bool
    {
        return in_array($c->device, self::AT_HOME, true) || $c->battery;
    }

    /** Only from real facts: the wizard doesn't know the size yet. */
    private static function small(RoleHintContext $c): bool
    {
        return ($c->memoryMb !== null && $c->memoryMb < self::SMALL_MEMORY_MB)
            || ($c->cpus !== null && $c->cpus < self::SMALL_CPUS);
    }

    /** "1 GB and 1 core", from what is known. */
    private static function size(RoleHintContext $c): string
    {
        $parts = [];

        if ($c->memoryMb !== null) {
            $parts[] = $c->memoryMb < 1024 ? "{$c->memoryMb} MB" : round($c->memoryMb / 1024, 1).' GB';
        }

        if ($c->cpus !== null) {
            $parts[] = $c->cpus === 1 ? '1 core' : "{$c->cpus} cores";
        }

        return implode(' and ', $parts);
    }

    private static function archLabel(string $arch): string
    {
        return $arch === 'arm64' ? 'ARM' : 'x86';
    }
}
