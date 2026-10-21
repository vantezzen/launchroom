<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Servers\DeviceData;
use App\Data\Servers\LocationData;
use App\Enums\ServerStatus;
use Spatie\LaravelData\Data;

/**
 * A runtime server in Settings → Scaling: for the allow/deny lists, with
 * whether the saved constraints let this service run there (and why not),
 * its capacity against what other services already hold, and where the
 * scheduler would put the saved replica count (`planned`).
 */
final class PlacementServerOptionData extends Data
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public string $id,
        public string $name,
        public ServerStatus $status,
        public array $labels,
        public ?string $platform,
        public bool $eligible,
        public ?string $reason,
        /** Replicas of this service's active deployment on it. */
        public int $replicas,
        /** Replicas the scheduler would place here with the saved settings. */
        public int $planned = 0,
        public ?DeviceData $device = null,
        public ?LocationData $location = null,
        /** Cores (null: unknown, no CPU check). */
        public ?int $cpus = null,
        /** Total memory (null: unknown, no memory check). */
        public ?int $memoryMb = null,
        /** Memory kept free for the system. */
        public int $reservedMemoryMb = 0,
        /** Memory and CPU limits of other services' serving replicas here. */
        public int $allocatedMemoryMb = 0,
        public float $allocatedCpus = 0.0,
        public bool $allowPreviews = true,
        public bool $allowProduction = true,
    ) {}
}
