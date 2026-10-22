<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * What launchroomd can do on its host (Report.host.runtime.capabilities,
 * D49). Unknown names from newer servers are dropped when stored, like
 * App\Enums\DaemonFeature.
 */
enum RuntimeCapability: string
{
    /** Full: through dockerd-guard (allow-listed API); degraded: launchroomd holds the full Docker socket. */
    case DockerAccess = 'dockerAccess';
    /** Public ports 80/443 for the edge. */
    case EdgePorts = 'edgePorts';
    /** Apps see visitor addresses (degraded on rootless Docker). */
    case ClientAddresses = 'clientAddresses';
    /** Network isolation between environments. */
    case Firewall = 'firewall';
    /** The private network to the other servers. */
    case Mesh = 'mesh';
    /** Detecting the ports an app listens on. */
    case PortDiscovery = 'portDiscovery';
    /** Apps here are reachable from the other servers (unavailable on Docker Desktop, D53). */
    case Workloads = 'workloads';
    /** BuildKit can run here (build servers only; unavailable when Ubuntu restricts user namespaces and launchroom's AppArmor profile is not loaded, D58). */
    case Builds = 'builds';
}
