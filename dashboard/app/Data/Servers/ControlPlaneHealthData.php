<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * `servers.control_plane_health`: what the control-plane server's watchdog
 * saw and did (Report.controlPlane, D90), whitelisted. Every report replaces
 * the readiness and the services; restarts and fallback notices are
 * delivered once, so the newest MAX_HISTORY of each are kept. Per service,
 * `badSince` is when it stopped running (for "unhealthy for 5 minutes");
 * `notReadyReports` counts reports in a row with the dashboard not ready.
 */
final class ControlPlaneHealthData extends Data
{
    public const int MAX_HISTORY = 20;

    /** Services the watchdog may restart (never Postgres or Valkey). */
    public const array RESTARTABLE = ['dashboard', 'horizon', 'reverb', 'scheduler', 'zot'];

    /**
     * @param  list<array{name: string, state: string, startedAt: string|null, restarts: int, oomKilled: bool, badSince: string|null}>  $services
     * @param  list<array{service: string, at: string, reason: string}>  $restarts
     * @param  list<array{kind: string, at: string, sent: bool, error: string|null}>  $notices
     */
    public function __construct(
        public string $checkedAt,
        public ?bool $ready = null,
        public int $notReadyReports = 0,
        public array $services = [],
        public array $restarts = [],
        public array $notices = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $report  Report.controlPlane, validated by Report::rules()
     */
    public static function merge(?self $previous, array $report, CarbonImmutable $now): self
    {
        $ready = is_bool($report['ready'] ?? null) ? $report['ready'] : null;
        $badSince = [];

        foreach ($previous->services ?? [] as $service) {
            $badSince[$service['name']] = $service['badSince'];
        }

        $services = [];

        foreach (is_array($report['services'] ?? null) ? $report['services'] : [] as $service) {
            if (! is_array($service) || ! is_string($service['name'] ?? null) || ! is_string($service['state'] ?? null)) {
                continue;
            }

            $bad = $service['state'] !== 'running';
            $services[] = [
                'name' => $service['name'],
                'state' => $service['state'],
                'startedAt' => is_string($service['startedAt'] ?? null) ? $service['startedAt'] : null,
                'restarts' => is_int($service['restarts'] ?? null) ? $service['restarts'] : 0,
                'oomKilled' => ($service['oomKilled'] ?? false) === true,
                'badSince' => $bad ? ($badSince[$service['name']] ?? $now->toIso8601ZuluString()) : null,
            ];
        }

        $restarts = $previous->restarts ?? [];

        foreach (is_array($report['restarts'] ?? null) ? $report['restarts'] : [] as $restart) {
            if (is_array($restart) && is_string($restart['service'] ?? null) && is_string($restart['at'] ?? null) && is_string($restart['reason'] ?? null)) {
                $restarts[] = ['service' => $restart['service'], 'at' => $restart['at'], 'reason' => $restart['reason']];
            }
        }

        $notices = $previous->notices ?? [];

        foreach (is_array($report['notices'] ?? null) ? $report['notices'] : [] as $notice) {
            if (is_array($notice) && is_string($notice['kind'] ?? null) && is_string($notice['at'] ?? null)) {
                $notices[] = [
                    'kind' => $notice['kind'],
                    'at' => $notice['at'],
                    'sent' => ($notice['sent'] ?? false) === true,
                    'error' => is_string($notice['error'] ?? null) ? mb_substr($notice['error'], 0, 512) : null,
                ];
            }
        }

        return new self(
            checkedAt: is_string($report['checkedAt'] ?? null) ? $report['checkedAt'] : $now->toIso8601ZuluString(),
            ready: $ready,
            notReadyReports: $ready === false ? ($previous->notReadyReports ?? 0) + 1 : 0,
            services: $services,
            restarts: array_slice(self::unique($restarts, 'service'), -self::MAX_HISTORY),
            notices: array_slice(self::unique($notices, 'kind'), -self::MAX_HISTORY),
        );
    }

    /**
     * Restarts at or after `$since`.
     *
     * @return list<array{service: string, at: string, reason: string}>
     */
    public function restartsSince(CarbonImmutable $since): array
    {
        return array_values(array_filter($this->restarts, static fn (array $restart): bool => CarbonImmutable::parse($restart['at'])->greaterThanOrEqualTo($since)));
    }

    /**
     * A re-sent restart or notice (an unacknowledged report, a notice retried
     * after a failed send) is kept once: the newest entry per what and when.
     *
     * @template T of array{at: string}
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private static function unique(array $items, string $what): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[($item[$what] ?? '').'|'.$item['at']] = $item;
        }

        return array_values($out);
    }
}
