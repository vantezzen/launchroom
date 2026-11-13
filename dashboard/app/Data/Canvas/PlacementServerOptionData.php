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
 * what it really uses now and has free, and where the scheduler would put
 * the saved replica count (`planned`). Limits are caps, not reservations
 * (D72): other services' limits are shown, never subtracted.
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
        /** Cores (null: unknown). */
        public ?int $cpus = null,
        /** Total memory (null: unknown, no memory check). */
        public ?int $memoryMb = null,
        /** Memory kept free for the system. */
        public int $reservedMemoryMb = 0,
        /** Cores and memory really in use now (latest minute); null when not reported. */
        public ?float $cpuInUse = null,
        public ?int $memoryInUseMb = null,
        /** Memory this service's replicas can still get here (null: unknown, no check). */
        public ?int $freeMemoryMb = null,
        /** CPU and memory limits of other services' replicas here (caps, shown only). */
        public float $limitCpus = 0.0,
        public int $limitMemoryMb = 0,
        public bool $allowPreviews = true,
        public bool $allowProduction = true,
    ) {}
}
