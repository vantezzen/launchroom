<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * Why a capability has its state (Report.host.runtime.capabilities[].reason,
 * D49, D53, D58). launchroomd sends the code and the facts behind it
 * (`message`: an error, the refused ports, a command, the build limits); the
 * dashboard words the two together.
 */
enum CapabilityReason: string
{
    case FullSocket = 'full_socket';
    case Vm = 'vm';
    case Rootless = 'rootless';
    case NoRootlessKitApi = 'no_rootlesskit_api';
    case PortsPending = 'ports_pending';
    case PortsRefused = 'ports_refused';
    case TurnedOff = 'turned_off';
    case Error = 'error';
    case DockerDesktop = 'docker_desktop';
    case UsernsProfile = 'userns_profile';
    case UsernsBlocked = 'userns_blocked';
    case NoSwap = 'no_swap';
    case BuildLimits = 'build_limits';

    /**
     * The sentence the server page shows for a capability row. `$engine`
     * names the VM ("OrbStack's Linux VM"); null when it says nothing beyond
     * the facts.
     */
    public function explain(RuntimeCapability $capability, ?DockerEngine $engine, ?string $facts): ?string
    {
        $vm = $engine instanceof DockerEngine && $engine->inVm() ? $engine->label() : 'the engine';

        return match ([$this, $capability]) {
            [self::FullSocket, RuntimeCapability::DockerAccess] => 'launchroom holds the full Docker socket. Re-run the installer to put the Docker guard in front of it.',
            [self::Vm, RuntimeCapability::EdgePorts] => "Docker runs in {$vm}'s Linux VM here, so ports 80 and 443 open inside the VM, not on your network. Let a public server serve traffic.",
            [self::Vm, RuntimeCapability::ClientAddresses] => "Connections reach the VM through {$vm}'s port forwarding, so apps see an internal address.",
            [self::Vm, RuntimeCapability::Firewall] => "Rules apply inside {$vm}'s Linux VM, where the containers run. They also keep apps away from the machine itself, whose gateway addresses are private and dropped.",
            [self::Vm, RuntimeCapability::Mesh] => "The private network runs inside {$vm}'s VM, behind its NAT, so peers usually connect through the relay.",
            [self::Rootless, RuntimeCapability::ClientAddresses] => 'Rootless Docker forwards connections, so apps see an internal address.',
            [self::Rootless, RuntimeCapability::Firewall] => 'Rules apply inside the rootless network namespace, where container traffic flows. RootlessKit keeps the server’s own interfaces separate.',
            [self::Rootless, RuntimeCapability::Mesh] => 'The private network runs inside the rootless network namespace. Its UDP traffic is NATed, so peers may connect through relays.',
            [self::NoRootlessKitApi, RuntimeCapability::EdgePorts] => 'Rootless Docker can’t open ports 80 and 443 here because the Docker guard has no access to RootlessKit. Run the server installer again with --rootless.',
            [self::PortsPending, RuntimeCapability::EdgePorts] => 'Ports 80 and 443 are not open yet.',
            [self::PortsRefused, RuntimeCapability::EdgePorts] => 'Not open: '.($facts ?? 'some ports').'. Ports below 1024 need net.ipv4.ip_unprivileged_port_start=80 on the host.',
            [self::TurnedOff, RuntimeCapability::Firewall] => 'Turned off on this server (LR_FIREWALL=off).',
            [self::DockerDesktop, RuntimeCapability::Workloads] => 'Docker Desktop opens published ports on the machine, not on the private network address inside its VM, so apps here can’t be reached from your other servers. Builds work. To run apps on a Mac, use OrbStack or Colima.',
            [self::UsernsProfile, RuntimeCapability::Builds] => 'Ubuntu restricts user namespaces here. launchroom’s AppArmor profile allows them for the build container only.',
            [self::UsernsBlocked, RuntimeCapability::Builds] => 'Ubuntu’s user-namespace restriction blocks builds here. Run the launchroom installer on this server again'.($facts === null ? '.' : ', or run: '.$facts),
            [self::NoSwap, RuntimeCapability::Builds] => 'This server has no swap, so a build that needs more memory fails instead of slowing down. Add 2 GB of swap by running the launchroom installer again'.($facts === null ? '.' : ', or run: '.$facts),
            default => $facts,
        };
    }
}
