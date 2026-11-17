<?php

declare(strict_types=1);

namespace App\Data\Instance\System;

use Spatie\LaravelData\Data;

/**
 * Settings → System (instance owners): how launchroom itself is doing on
 * the main server. Every part is null when it can't be read right now (the
 * cache is down, the main server never reported it), never an error.
 *
 * - `checks`: the same checks as `./launchroom doctor`'s dashboard group;
 * - `services`, `events`: Report.controlPlane of the main server (watchdog);
 * - `queues`, `workers`, `failedJobs`: Horizon;
 * - storage in MB/bytes; `backup` is the control-plane backup's health;
 * - `certificate`: the dashboard domain's certificate after a switch.
 *
 * `workers`: running, paused or stopped.
 */
final class SystemHealthData extends Data
{
    /**
     * @param  list<SystemCheckData>  $checks
     * @param  list<SystemServiceData>|null  $services
     * @param  list<SystemEventData>  $events
     * @param  list<SystemQueueData>|null  $queues
     * @param  list<SystemFailedJobData>|null  $failedJobs
     * @param  list<string>  $staleLocks
     */
    public function __construct(
        public string $checkedAt,
        public array $checks,
        public ?array $services,
        public ?string $servicesCheckedAt,
        public ?bool $ready,
        public array $events,
        public ?string $workers,
        public ?array $queues,
        public ?array $failedJobs,
        public ?string $schedulerBeatAt,
        public array $staleLocks,
        public ?int $diskFreeMb,
        public ?int $diskTotalMb,
        public ?int $registryMb,
        public ?int $databaseBytes,
        public ?int $cacheBytes,
        public string $backupStatus,
        public ?string $backupLastSuccessAt,
        public ?string $backupNextRunAt,
        public ?string $certificateHost,
        public ?string $certificateExpiresAt,
        public string $version,
        public ?string $serverVersion,
        public ?string $databaseVersion,
        public ?string $cacheVersion,
        public ?string $horizonUrl,
    ) {}
}
