<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Protocol\V1\IngressStatus;
use App\Data\Protocol\V1\Report;
use App\Data\Protocol\V1\WafStatus;
use App\Models\Server;
use App\Queries\RouteOwners;
use App\Support\Security\WafSamples;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * The web firewall part of an edge's report (docs/22 §6.5–6.6, docs/03
 * §13.4):
 *
 * - `ingress.waf` → `servers.waf_status` with the time its state began
 *   (the `waf_unavailable` alert waits 5 minutes of `error`);
 * - `metrics.waf` → 10-minute `waf_samples` rows and `waf_events`,
 *   attributed by route like request metrics. Upserts and an insert that
 *   ignores known (server, reference, time) keep a window that arrives
 *   twice from counting twice. Unknown routes are dropped.
 */
final readonly class RecordWafReport
{
    public function __construct(
        private RouteOwners $owners,
    ) {}

    /**
     * @return int samples stored
     */
    public function handle(Server $server, Report $report): int
    {
        $ingress = $report->ingress instanceof IngressStatus ? $report->ingress : null;

        if ($ingress instanceof IngressStatus && $ingress->waf instanceof WafStatus) {
            $this->status($server, $ingress->waf);
        }

        return is_array($report->metrics) ? $this->samples($server, $report->metrics) : 0;
    }

    private function status(Server $server, WafStatus $waf): void
    {
        $now = CarbonImmutable::now()->toIso8601ZuluString();
        $previous = $server->waf_status;
        $since = $previous !== null && $previous['state'] === $waf->state ? $previous['since'] : $now;

        $server->forceFill(['waf_status' => array_filter([
            'state' => $waf->state,
            'since' => $since,
            'engine' => $waf->engine instanceof Optional ? null : $waf->engine,
            'ruleSet' => $waf->ruleSet instanceof Optional ? null : $waf->ruleSet,
            'routes' => $waf->routes,
            'droppedRecords' => $waf->droppedRecords instanceof Optional ? null : $waf->droppedRecords,
            'message' => $waf->message instanceof Optional ? null : mb_substr($waf->message, 0, 1024),
            'reportedAt' => $now,
        ], static fn (mixed $value): bool => $value !== null)])->save();
    }

    /**
     * @param  array<array-key, mixed>  $metrics
     */
    private function samples(Server $server, array $metrics): int
    {
        $rows = WafSamples::parse($metrics, CarbonImmutable::now());

        if ($rows === []) {
            return 0;
        }

        $owners = $this->owners->handle($server, array_values(array_unique(array_column($rows, 'route_id'))));
        $samples = [];
        $events = [];

        foreach ($rows as $row) {
            $owner = $owners[$row['route_id']] ?? null;

            if ($owner === null || $owner[0] === 0) {
                continue;
            }

            $samples[] = [
                'server_id' => $server->id,
                'route_id' => $row['route_id'],
                'resolution' => RollupWafSamples::TEN_MINUTES,
                'at' => $row['at']->toIso8601String(),
                'service_instance_id' => $owner[0],
                'project_id' => $owner[1],
                'blocked' => $row['blocked'],
                'detected' => $row['detected'],
                'categories' => json_encode($row['categories'], JSON_THROW_ON_ERROR),
                'rules' => json_encode($row['rules'], JSON_THROW_ON_ERROR),
                'paths' => json_encode($row['paths'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'other_paths_blocked' => $row['other_paths_blocked'],
                'other_paths_detected' => $row['other_paths_detected'],
            ];

            foreach ($row['events'] as $event) {
                $events[] = [
                    'server_id' => $server->id,
                    'route_id' => $row['route_id'],
                    'service_instance_id' => $owner[0],
                    'project_id' => $owner[1],
                    'at' => $event['at']->format('Y-m-d\TH:i:s.vP'),
                    'action' => $event['action'],
                    'method' => $event['method'],
                    'path' => $event['path'],
                    'rule_ids' => json_encode($event['rules'], JSON_THROW_ON_ERROR),
                    'categories' => json_encode($event['categories'], JSON_THROW_ON_ERROR),
                    'targets' => json_encode($event['targets'], JSON_THROW_ON_ERROR),
                    'client_network' => $event['client_network'],
                    'score' => $event['score'],
                    'reference' => $event['reference'],
                ];
            }
        }

        foreach (array_chunk($samples, 500) as $chunk) {
            DB::table('waf_samples')->upsert(
                $chunk,
                ['server_id', 'route_id', 'resolution', 'at'],
                ['blocked', 'detected', 'categories', 'rules', 'paths', 'other_paths_blocked', 'other_paths_detected'],
            );
        }

        foreach (array_chunk($events, 500) as $chunk) {
            DB::table('waf_events')->insertOrIgnore($chunk);
        }

        return count($samples);
    }
}
