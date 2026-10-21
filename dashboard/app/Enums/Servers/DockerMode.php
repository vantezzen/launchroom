<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * How Docker runs on a server (Report.host.runtime.docker, D49): as root, or
 * rootless (the daemon and the apps run as an unprivileged user).
 */
enum DockerMode: string
{
    case Rootful = 'rootful';
    case Rootless = 'rootless';
}
