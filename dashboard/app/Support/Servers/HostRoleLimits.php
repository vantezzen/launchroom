<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Data\Servers\HostFactsData;
use App\Data\Servers\ServerRuntimeData;
use App\Enums\ServerRole;
use App\Enums\Servers\CapabilityState;
use App\Enums\Servers\DockerEngine;
use App\Enums\Servers\HostOs;
use App\Enums\Servers\RuntimeCapability;
use App\Models\Server;

/**
 * Roles a machine can't take because of how Docker runs on it (D53, docs/11
 * §3.3). Pure; reads what the server reported:
 *
 * | Machine                        | edge | runtime | build |
 * |--------------------------------|------|---------|-------|
 * | Linux (Docker Engine)          | yes  | yes     | yes   |
 * | Mac, OrbStack or Colima        | no   | yes     | yes   |
 * | Mac (or any), Docker Desktop   | no   | no      | yes   |
 *
 * A Mac never serves traffic: Docker runs in the engine's Linux VM, whose
 * ports 80 and 443 are not on the machine's network (and a home Mac behind
 * NAT has no public address anyway). Docker Desktop opens published ports on
 * the machine instead of on the mesh address inside its VM, so apps there
 * can't be reached from other servers (capability `workloads`).
 *
 * The Mac is known from the enrollment (`facts.os` = darwin, from the
 * installer) or the runtime report (`runtime.hostOs`); the engine from the
 * runtime report, else Docker's OS name in the hardware facts.
 */
final class HostRoleLimits
{
    public static function isMac(Server $server): bool
    {
        return self::isMacHost($server->runtime, $server->facts);
    }

    public static function engine(Server $server): ?DockerEngine
    {
        return self::engineOf($server->runtime, $server->facts);
    }

    /** Why the server can't take the role, or null when it can. */
    public static function refusal(Server $server, ServerRole $role): ?string
    {
        return self::refusalFor($role, $server->runtime, $server->facts);
    }

    /**
     * The editable roles the server can't take, with the reason (server page).
     *
     * @return array<string, string>
     */
    public static function limits(Server $server): array
    {
        $limits = [];

        foreach ([ServerRole::Runtime, ServerRole::Edge, ServerRole::Build] as $role) {
            $refusal = self::refusal($server, $role);

            if ($refusal !== null) {
                $limits[$role->value] = $refusal;
            }
        }

        return $limits;
    }

    /**
     * The roles of $roles the machine can take; build when none is left
     * (every machine builds).
     *
     * @param  list<ServerRole>  $roles
     * @return list<ServerRole>
     */
    public static function allowed(array $roles, ?ServerRuntimeData $runtime, ?HostFactsData $facts): array
    {
        $allowed = array_values(array_filter($roles, static fn (ServerRole $role): bool => self::refusalFor($role, $runtime, $facts) === null));

        return $allowed === [] ? [ServerRole::Build] : $allowed;
    }

    public static function refusalFor(ServerRole $role, ?ServerRuntimeData $runtime, ?HostFactsData $facts): ?string
    {
        $engine = self::engineOf($runtime, $facts);
        $mac = self::isMacHost($runtime, $facts);

        if ($role === ServerRole::Edge && ($mac || $engine === DockerEngine::DockerDesktop)) {
            return ($mac ? 'A Mac' : 'This machine').' can’t serve traffic: its Docker runs in a Linux VM whose ports 80 and 443 aren’t on your network. Use a public server to serve traffic. It reaches apps here over the private network.';
        }

        $workloads = $runtime?->capability(RuntimeCapability::Workloads);

        if ($role === ServerRole::Runtime && ($workloads === CapabilityState::Unavailable || (! $workloads instanceof CapabilityState && $engine === DockerEngine::DockerDesktop))) {
            return 'Docker Desktop can’t make apps on this machine reachable from your other servers. It can still build. To run apps on a Mac, switch to OrbStack or Colima and run the install command again.';
        }

        return null;
    }

    private static function isMacHost(?ServerRuntimeData $runtime, ?HostFactsData $facts): bool
    {
        return $runtime?->hostOs === HostOs::Darwin || strtolower($facts->os ?? '') === HostOs::Darwin->value;
    }

    private static function engineOf(?ServerRuntimeData $runtime, ?HostFactsData $facts): ?DockerEngine
    {
        if ($runtime?->engine instanceof DockerEngine) {
            return $runtime->engine;
        }

        return match (strtolower($facts?->hardware->osName ?? '')) {
            'docker desktop' => DockerEngine::DockerDesktop,
            'orbstack' => DockerEngine::OrbStack,
            default => null,
        };
    }
}
