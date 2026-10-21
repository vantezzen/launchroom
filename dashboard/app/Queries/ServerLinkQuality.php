<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\LinkQualityData;
use App\Data\Servers\LinkSpeedData;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Measured private-network link quality (docs/05 §2.1) from
 * `server_links` (latest per direction) and `server_link_samples` (hourly
 * rollups). Latency counts as current for 10 minutes (probes run every
 * minute), a transfer rate for 2 days (transfers run every few hours).
 * Callers authorize and pass only servers the viewer may see.
 */
final readonly class ServerLinkQuality
{
    public const int LATENCY_FRESH_MINUTES = 10;

    public const int THROUGHPUT_FRESH_HOURS = 48;

    /**
     * Per pair of the given servers: the mean current round trip of both
     * directions and the latest rate per direction.
     *
     * @param  list<Server>  $servers
     * @return array<string, array{latencyMs: float|null, speeds: list<LinkSpeedData>}> keyed "<id a>|<id b>" (public ids, a < b)
     */
    public function pairs(array $servers): array
    {
        $byId = [];

        foreach ($servers as $server) {
            $byId[$server->id] = $server;
        }

        if (count($byId) < 2) {
            return [];
        }

        $now = CarbonImmutable::now();
        $rows = DB::table('server_links')
            ->whereIn('server_id', array_keys($byId))
            ->whereIn('peer_server_id', array_keys($byId))
            ->get();

        $latency = [];
        $speeds = [];

        foreach ($rows as $row) {
            $from = $byId[$this->int($row->peer_server_id)] ?? null;
            $to = $byId[$this->int($row->server_id)] ?? null;

            if (! $from instanceof Server || ! $to instanceof Server) {
                continue;
            }

            [$a, $b] = strcmp($from->public_id, $to->public_id) < 0 ? [$from, $to] : [$to, $from];
            $key = $a->public_id.'|'.$b->public_id;

            if (is_numeric($row->rtt_ms) && $this->fresh($row->measured_at, $now->subMinutes(self::LATENCY_FRESH_MINUTES))) {
                $latency[$key][] = (float) $row->rtt_ms;
            }

            if (is_numeric($row->throughput_mbps) && $this->fresh($row->throughput_at, $now->subHours(self::THROUGHPUT_FRESH_HOURS))) {
                // The measuring server downloads from its peer: data flows peer → server.
                $speeds[$key][] = new LinkSpeedData($from->public_id, $to->public_id, round((float) $row->throughput_mbps, 1));
            }
        }

        $out = [];

        foreach (array_unique([...array_keys($latency), ...array_keys($speeds)]) as $key) {
            $values = $latency[$key] ?? [];
            $list = $speeds[$key] ?? [];
            usort($list, static fn (LinkSpeedData $x, LinkSpeedData $y): int => strcmp($x->from, $y->from));
            $out[$key] = [
                'latencyMs' => $values === [] ? null : round(array_sum($values) / count($values), 1),
                'speeds' => $list,
            ];
        }

        return $out;
    }

    /**
     * The quality from $server to each of its measured peers, keyed by the
     * peer's id.
     *
     * @return array<int, LinkQualityData>
     */
    public function forServer(Server $server): array
    {
        $now = CarbonImmutable::now();
        $since = $now->subHours(23)->startOfHour();
        $rows = DB::table('server_links')->where('server_id', $server->id)->get()->keyBy('peer_server_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $peerIds = array_map(intval(...), $rows->keys()->all());
        // The other direction: what each peer downloaded from this server.
        $reverse = DB::table('server_links')
            ->where('peer_server_id', $server->id)
            ->whereIn('server_id', $peerIds)
            ->get()
            ->keyBy('server_id');

        $samples = DB::table('server_link_samples')
            ->where('server_id', $server->id)
            ->whereIn('peer_server_id', $peerIds)
            ->where('at', '>=', $since)
            ->orderBy('at')
            ->get()
            ->groupBy('peer_server_id');

        $out = [];

        foreach ($rows as $peerId => $row) {
            $history = array_fill(0, 24, null);
            $probes = 0;
            $failed = 0;

            foreach ($samples->get($peerId) ?? [] as $sample) {
                $slot = (int) floor($since->diffInHours(CarbonImmutable::parse($this->str($sample->at)), true));

                if ($slot < 0 || $slot > 23) {
                    continue;
                }

                $n = $this->int($sample->samples);
                $probes += $n + $this->int($sample->failures);
                $failed += $this->int($sample->failures);

                if ($n > 0) {
                    $history[$slot] = round($this->float($sample->rtt_sum_ms) / $n, 1);
                }
            }

            $measuredAt = CarbonImmutable::parse($this->str($row->measured_at));
            $current = is_numeric($row->rtt_ms) && $measuredAt->greaterThanOrEqualTo($now->subMinutes(self::LATENCY_FRESH_MINUTES));
            /** @var \stdClass|null $back the peer's own measurement of this link */
            $back = $reverse->get($peerId);
            $download = $this->rate($row, $now);
            $upload = null;
            $throughputAt = array_filter([$download === null ? null : $row->throughput_at], is_string(...));

            if ($back !== null) {
                $upload = $this->rate($back, $now);
                $throughputAt[] = $upload === null ? null : $back->throughput_at;
                $throughputAt = array_filter($throughputAt, is_string(...));
            }

            $out[(int) $peerId] = new LinkQualityData(
                latencyMs: $current ? round((float) $row->rtt_ms, 1) : null,
                measuredAt: $measuredAt,
                error: is_string($row->error) ? $row->error : null,
                latencyHistory: $history,
                probes: $probes,
                failedProbes: $failed,
                downloadMbps: $download,
                uploadMbps: $upload,
                throughputAt: $throughputAt === [] ? null : CarbonImmutable::parse(max($throughputAt)),
            );
        }

        return $out;
    }

    private function rate(object $row, CarbonImmutable $now): ?float
    {
        $mbps = $row->throughput_mbps ?? null;

        return is_numeric($mbps) && $this->fresh($row->throughput_at ?? null, $now->subHours(self::THROUGHPUT_FRESH_HOURS))
            ? round((float) $mbps, 1)
            : null;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function fresh(mixed $at, CarbonImmutable $since): bool
    {
        return is_string($at) && CarbonImmutable::parse($at)->greaterThanOrEqualTo($since);
    }
}
