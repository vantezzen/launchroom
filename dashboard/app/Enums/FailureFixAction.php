<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The one-click fix an explained deployment failure offers
 * (App\Support\Deployments\Failures\FailureCatalogue, direction.md §9).
 * `set_port` and `raise_health_timeout` stage the setting and redeploy;
 * `add_variable` opens the variable sheet with the name filled in;
 * `generate_dockerfile` opens the guided sheet in Settings → Build.
 */
enum FailureFixAction: string
{
    case SetPort = 'set_port';
    case RaiseHealthTimeout = 'raise_health_timeout';
    case AddVariable = 'add_variable';
    /** Opens "Generate a Dockerfile" in Settings → Build (docs/20 §3). */
    case GenerateDockerfile = 'generate_dockerfile';
    /** Switches the health check to TCP and redeploys (apps that answer no HTTP on the path). */
    case UseTcpHealthcheck = 'use_tcp_healthcheck';
    /** Accepts 2xx-4xx on the health path and redeploys (apps without a page on `/`). */
    case Accept4xx = 'accept_4xx';
    /** Grants the capabilities an image that switches users needs and redeploys. */
    case AddCapabilities = 'add_capabilities';
    /** Gives a volume to the image's user on the next start and redeploys. */
    case FixVolumeOwner = 'fix_volume_owner';
    /** Frees disk space on the server (a `cleanup` task), then retries. */
    case FreeDisk = 'free_disk';
}
