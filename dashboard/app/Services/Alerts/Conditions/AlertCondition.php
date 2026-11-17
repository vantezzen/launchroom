<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Data\Alerts\ResolvedRule;
use App\Enums\Alerts\AlertRule;
use App\Models\Server;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Closure;

/**
 * One condition rule of the per-minute evaluator (docs/15 §3.4): which
 * subjects fail it right now and what a resolve says. Thresholds have
 * hysteresis (a firing subject keeps failing until a lower bar), so a value
 * hovering at the threshold doesn't flap.
 */
abstract readonly class AlertCondition
{
    abstract public function rule(): AlertRule;

    abstract public function resolvedReason(): string;

    /**
     * Failing subjects keyed "team id|subject key", each with the signal to raise.
     *
     * @param  array<int, array<string, ResolvedRule>>  $rules  every team's resolved rules
     * @param  array<string, true>  $firing  "team id|subject key" of this rule's firing alerts
     * @return array<string, Closure(): AlertSignal>
     */
    abstract public function failing(array $rules, array $firing, CarbonImmutable $now): array;

    protected const int WINDOW_MINUTES = 30;

    /**
     * Only alerts of the rule whose subject starts with this belong to the
     * condition (a rule that other code raises too, like `backup_failed`);
     * null: every alert of the rule.
     */
    public function subjectPrefix(): ?string
    {
        return null;
    }

    /**
     * The control-plane server, whose team hears about launchroom itself
     * (the dashboard, its queues, its backups).
     */
    protected function controlPlaneServer(): ?Server
    {
        return Server::query()->controlPlane()->orderBy('id')->first();
    }

    protected function team(int $id): Team
    {
        return Team::query()->whereKey($id)->firstOrFail();
    }

    /**
     * Servers that report (online or just unreachable), by id.
     *
     * @return array<int, Server>
     */
    protected function activeServers(): array
    {
        $out = [];

        foreach (Server::query()->reachable()->get() as $server) {
            $out[$server->id] = $server;
        }

        return $out;
    }

    protected function percent(float $value): string
    {
        return ($value >= 10 ? (string) round($value) : number_format($value, 1)).'%';
    }
}
