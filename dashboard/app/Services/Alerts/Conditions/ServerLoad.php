<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\ServerTab;
use App\Models\Server;
use App\Support\Links;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

abstract readonly class ServerLoad extends AlertCondition
{
    /** The measured share in percent of one sample, null when the sample has none. */
    abstract protected function value(object $sample): ?float;

    /** What is measured: "CPU", "Memory". */
    abstract protected function measure(): string;

    abstract protected function title(Server $server): string;

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $rule = $this->rule();
        $servers = $this->activeServers();
        $rows = DB::table('server_metric_samples')
            ->whereIn('server_id', array_keys($servers))
            ->where('resolution', 1)
            ->where('at', '>=', $now->subMinutes(self::WINDOW_MINUTES)->toIso8601String())
            ->get(['server_id', 'at', 'cpu_pct', 'mem_used_mb', 'mem_total_mb']);

        /** @var array<int, list<float>> $values */
        $values = [];

        foreach ($rows as $row) {
            $server = $servers[Cast::id($row->server_id ?? null)] ?? null;
            $settings = $server === null ? null : ($rules[$server->team_id][$rule->value] ?? null);

            if ($server === null || $settings === null || CarbonImmutable::parse(Cast::string($row->at ?? null))->lessThan($now->subMinutes($settings->minutes()))) {
                continue;
            }

            $value = $this->value($row);

            if ($value !== null) {
                $values[$server->id][] = $value;
            }
        }

        $out = [];

        foreach ($values as $serverId => $list) {
            $server = $servers[$serverId];
            $settings = $rules[$server->team_id][$rule->value];
            $minutes = $settings->minutes();
            $threshold = $settings->thresholdValue();

            if (count($list) < (int) ceil($minutes * 0.6)) {
                continue;
            }

            $average = array_sum($list) / count($list);
            $key = $server->team_id.'|server:'.$server->public_id;

            if ($average < (isset($firing[$key]) ? $threshold - 10 : $threshold)) {
                continue;
            }

            $out[$key] = fn (): AlertSignal => new AlertSignal(
                team: $this->team($server->team_id),
                rule: $rule,
                subject: 'server:'.$server->public_id,
                title: $this->title($server),
                summary: "{$this->measure()} averaged ".$this->percent($average)." over the last {$minutes} minutes. The server page shows what uses it.",
                href: Links::server($server, ServerTab::Resources->value, ['range' => '1h']),
                server: $server,
                facts: [$this->measure() => $this->percent($average)],
                value: $average,
            );
        }

        return $out;
    }
}
