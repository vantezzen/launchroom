<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Data\Servers\ControlPlaneHealthData;
use App\Enums\Alerts\AlertRule;
use App\Models\Server;
use Carbon\CarbonImmutable;

/**
 * `control_plane_degraded` (G41, D90): what the control-plane server's
 * watchdog reports (Report.controlPlane, `servers.control_plane_health`):
 *
 * - the dashboard wasn't ready in NOT_READY_REPORTS reports in a row;
 * - a service the watchdog looks after (dashboard, Horizon, Reverb,
 *   scheduler, registry) isn't running for DOWN_MINUTES, or ran out of
 *   memory;
 * - the watchdog restarted one within the last RESTART_WINDOW_MINUTES.
 *
 * Restarts are folded into this one alert instead of one message each. It
 * goes to the control-plane server's team; stale reports (the watchdog is
 * off) raise nothing.
 */
final readonly class ControlPlaneDegraded extends AlertCondition
{
    public const int NOT_READY_REPORTS = 2;

    public const int DOWN_MINUTES = 5;

    public const int RESTART_WINDOW_MINUTES = 60;

    public const int STALE_MINUTES = 10;

    private const array NAMES = ['dashboard' => 'the dashboard', 'horizon' => 'Horizon', 'reverb' => 'Reverb', 'scheduler' => 'the scheduler', 'zot' => 'the image registry', 'postgres' => 'Postgres', 'valkey' => 'Valkey'];

    private const array REASONS = ['unhealthy' => 'its health check failed 3 times', 'not_ready' => 'it stopped responding', 'exited' => 'it stopped unexpectedly'];

    public function rule(): AlertRule
    {
        return AlertRule::ControlPlaneDegraded;
    }

    public function subjectPrefix(): string
    {
        return 'control_plane:';
    }

    public function resolvedReason(): string
    {
        return 'launchroom’s services run normally again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $server = $this->controlPlaneServer();
        $health = $server?->control_plane_health;

        if (! $server instanceof Server || ! $health instanceof ControlPlaneHealthData || CarbonImmutable::parse($health->checkedAt)->lessThan($now->subMinutes(self::STALE_MINUTES))) {
            return [];
        }

        $reasons = $this->reasons($health, $now);

        if ($reasons === []) {
            return [];
        }

        return [$server->team_id.'|control_plane:services' => fn (): AlertSignal => new AlertSignal(
            team: $this->team($server->team_id),
            rule: AlertRule::ControlPlaneDegraded,
            subject: 'control_plane:services',
            title: 'launchroom has trouble on '.$server->name,
            summary: mb_substr(implode(' ', $reasons).' Your apps keep running.', 0, 1000),
            href: route('system.show', [], false),
            server: $server,
        )];
    }

    /**
     * @return list<string>
     */
    private function reasons(ControlPlaneHealthData $health, CarbonImmutable $now): array
    {
        $out = [];

        if ($health->notReadyReports >= self::NOT_READY_REPORTS) {
            $out[] = 'The dashboard doesn’t answer its readiness check.';
        }

        foreach ($health->services as $service) {
            $name = self::NAMES[$service['name']] ?? $service['name'];

            if ($service['oomKilled']) {
                $out[] = ucfirst($name).' ran out of memory.';
            } elseif ($service['badSince'] !== null && CarbonImmutable::parse($service['badSince'])->lessThanOrEqualTo($now->subMinutes(self::DOWN_MINUTES))) {
                $out[] = ucfirst($name).' isn’t running ('.$service['state'].').';
            }
        }

        foreach ($health->restartsSince($now->subMinutes(self::RESTART_WINDOW_MINUTES)) as $restart) {
            $at = CarbonImmutable::parse($restart['at'])->format('H:i');
            $out[] = 'launchroom restarted '.(self::NAMES[$restart['service']] ?? $restart['service'])." at {$at} UTC: ".(self::REASONS[$restart['reason']] ?? $restart['reason']).'.';
        }

        return array_values(array_unique($out));
    }
}
