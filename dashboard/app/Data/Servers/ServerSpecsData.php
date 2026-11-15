<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Models\Server;
use Spatie\LaravelData\Data;

/**
 * The spec line of a server card ("4 CPUs · 8 GB · 80 GB · arm64"), from the
 * latest report, falling back to enrollment facts.
 */
final class ServerSpecsData extends Data
{
    public function __construct(
        public ?int $cpus,
        public ?int $memoryMb,
        public ?int $diskTotalMb,
        public ?string $arch,
        public ?string $cpuModel,
        public ?string $osName,
    ) {}

    public static function fromServer(Server $server): self
    {
        return new self(
            $server->cpus(),
            $server->memoryMb(),
            $server->host_status?->volumeDiskTotalMb(),
            $server->facts->arch,
            $server->facts->hardware?->cpuModel,
            $server->facts->hardware?->osName,
        );
    }
}
