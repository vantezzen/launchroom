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
        $status = $server->host_status ?? [];
        $int = static fn (mixed $value): ?int => is_int($value) && $value > 0 ? $value : null;
        $health = is_array($status['health'] ?? null) ? $status['health'] : [];
        $docker = is_array($health['dockerDisk'] ?? null) ? ($health['dockerDisk']['totalMb'] ?? null) : null;

        return new self(
            $int($status['cpus'] ?? null) ?? $server->facts->cpus,
            $int($status['memoryMb'] ?? null) ?? $server->facts->memoryMb,
            $int($docker) ?? $int($status['diskTotalMb'] ?? null),
            $server->facts->arch,
            $server->facts->hardware?->cpuModel,
            $server->facts->hardware?->osName,
        );
    }
}
