<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Support\Links;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DiskFull extends AlertCondition
{
    public function rule(): AlertRule
    {
        return AlertRule::DiskFull;
    }

    public function resolvedReason(): string
    {
        return 'There is enough free disk space again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $servers = $this->activeServers();
        $rows = DB::table('server_metric_samples')
            ->selectRaw('DISTINCT ON (server_id) server_id, disk_used_mb, disk_total_mb')
            ->whereIn('server_id', array_keys($servers))
            ->where('resolution', 1)
            ->where('at', '>=', $now->subMinutes(15)->toIso8601String())
            ->whereNotNull('disk_used_mb')
            ->where('disk_total_mb', '>', 0)
            ->orderBy('server_id')
            ->orderByDesc('at')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $server = $servers[Cast::id($row->server_id ?? null)] ?? null;

            if ($server === null) {
                continue;
            }

            $used = Cast::id($row->disk_used_mb ?? null);
            $total = Cast::id($row->disk_total_mb ?? null);
            $pct = 100 * $used / max(1, $total);
            $threshold = $rules[$server->team_id][AlertRule::DiskFull->value]->thresholdValue();
            $key = $server->team_id.'|server:'.$server->public_id;

            if ($pct < (isset($firing[$key]) ? $threshold - 5 : $threshold)) {
                continue;
            }

            $out[$key] = fn (): AlertSignal => new AlertSignal(
                team: $this->team($server->team_id),
                rule: AlertRule::DiskFull,
                subject: 'server:'.$server->public_id,
                title: "{$server->name} is running out of disk space",
                summary: $this->gigabytes($used).' of '.$this->gigabytes($total).' used. launchroom removes old images and build cache automatically, but not unused volumes or backups on the server.',
                severity: $pct >= 95 ? AlertSeverity::Critical : AlertSeverity::Warning,
                href: Links::server($server, null, [], 'disk'),
                server: $server,
                facts: ['Disk' => $this->percent($pct).' full'],
                value: $pct,
            );
        }

        return $out;
    }

    private function gigabytes(int $mb): string
    {
        $gb = $mb / 1024;

        return ($gb >= 10 ? (string) round($gb) : number_format($gb, 1)).' GB';
    }
}
