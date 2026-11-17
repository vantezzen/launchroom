<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Servers\DaemonExitKind;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * `servers.last_daemon_exit`: launchroomd's last unclean exit or the
 * machine's last restart (Report.host.daemon, docs/03 §9). Launchroomd
 * reports it once; it is kept here until a newer one replaces it. `at` is
 * its last heartbeat before the exit (null when unknown); `panic` the crash
 * trace (≤ 8 KiB, control characters removed by launchroomd), only shown
 * to admins.
 */
final class DaemonExitData extends Data
{
    /** launchroomd's stall note starts with this (internal/watchdog). */
    public const string STALL_PREFIX = 'launchroomd stalled:';

    public function __construct(
        public DaemonExitKind $kind,
        public ?string $at,
        public string $reportedAt,
        public ?string $panic = null,
        public ?string $bootedAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $daemon  Report.host.daemon, validated by HostStatus::rules()
     */
    public static function fromReport(array $daemon): ?self
    {
        $exit = $daemon['previousExit'] ?? null;
        $booted = is_string($daemon['hostBootedAt'] ?? null) ? $daemon['hostBootedAt'] : null;

        if (! is_array($exit) || ($exit['unclean'] ?? false) !== true) {
            return null;
        }

        $at = is_string($exit['at'] ?? null) ? $exit['at'] : null;
        $panic = is_string($exit['panic'] ?? null) && trim($exit['panic']) !== '' ? mb_substr($exit['panic'], 0, 8192) : null;
        $rebooted = $booted !== null && $at !== null && CarbonImmutable::parse($booted)->greaterThan(CarbonImmutable::parse($at));

        $kind = match (true) {
            $panic !== null && str_starts_with($panic, self::STALL_PREFIX) => DaemonExitKind::Stalled,
            $panic !== null => DaemonExitKind::Crash,
            $rebooted => DaemonExitKind::Reboot,
            default => DaemonExitKind::Unexpected,
        };

        return new self($kind, $at, now()->toIso8601ZuluString(), $panic, $booted);
    }
}
