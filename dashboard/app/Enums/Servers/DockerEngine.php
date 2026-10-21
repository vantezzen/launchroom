<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * The Docker distribution a server runs (Report.host.runtime.engine, D53):
 * Docker Engine on Linux, or a macOS engine that runs Docker in a Linux VM.
 */
enum DockerEngine: string
{
    case DockerEngine = 'docker-engine';
    case DockerDesktop = 'docker-desktop';
    case OrbStack = 'orbstack';
    case Colima = 'colima';

    public function label(): string
    {
        return match ($this) {
            self::DockerEngine => 'Docker Engine',
            self::DockerDesktop => 'Docker Desktop',
            self::OrbStack => 'OrbStack',
            self::Colima => 'Colima',
        };
    }

    /** Docker runs in a Linux VM; the machine's network only sees the engine's port forwarding. */
    public function inVm(): bool
    {
        return $this !== self::DockerEngine;
    }
}
