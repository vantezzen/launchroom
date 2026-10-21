<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores a server's private-network link measurements (Report.host.links,
 * docs/05 §2.1): the latest one per peer in `server_links` and hourly
 * rollups in `server_link_samples`.
 *
 * Reports repeat the latest measurement until the next one, so a
 * measurement is counted once: only when its `measuredAt` (or, for
 * throughput, `throughputAt`) is newer than the stored one.
 *
 * Peers are whitelisted: a non-revoked server of the same team, or the
 * control plane's server (every server measures its path to the dashboard),
 * or any server when the reporter is the control plane. Measurements about
 * anything else, from the future (> 5 min) or older than a day are dropped.
 */
final readonly class RecordLinkQuality
{
    /**
     * @param  list<array<string, mixed>>  $links  validated Report.host.links
     */
    public function handle(Server $server, array $links): void
    {
        if ($links === []) {
            return;
        }

        $publicIds = [];

        foreach ($links as $link) {
            if (is_string($link['peer'] ?? null)) {
                $publicIds[] = $link['peer'];
            }
        }

        $peers = Server::query()
            ->whereIn('public_id', array_values(array_unique($publicIds)))
            ->whereKeyNot($server->id)
            ->whereNull('revoked_at')
            ->when(! $server->is_control_plane, fn ($query) => $query->where(
                fn ($q) => $q->where('team_id', $server->team_id)->orWhere('is_control_plane', true),
            ))
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        if ($peers->isEmpty()) {
            return;
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($server, $links, $peers, $now): void {
            $stored = DB::table('server_links')
                ->where('server_id', $server->id)
                ->whereIn('peer_server_id', $peers->pluck('id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('peer_server_id');

            foreach ($links as $link) {
                $peer = $peers->get(is_string($link['peer'] ?? null) ? $link['peer'] : '');
                $measuredAt = $this->time($link['measuredAt'] ?? null, $now);

                if (! $peer instanceof Server || ! $measuredAt instanceof CarbonImmutable) {
                    continue;
                }

                $rtt = is_numeric($link['rttMs'] ?? null) ? (float) $link['rttMs'] : null;
                $mbps = is_numeric($link['throughputMbps'] ?? null) ? (float) $link['throughputMbps'] : null;
                $throughputAt = $mbps === null ? null : $this->time($link['throughputAt'] ?? null, $now);
                $error = $rtt === null && is_string($link['error'] ?? null) ? mb_substr($link['error'], 0, 256) : null;

                $prev = $stored->get($peer->id);
                $prevMeasured = is_object($prev) && is_string($prev->measured_at ?? null) ? CarbonImmutable::parse($prev->measured_at) : null;
                $prevThroughput = is_object($prev) && is_string($prev->throughput_at ?? null) ? CarbonImmutable::parse($prev->throughput_at) : null;

                $newMeasurement = ! $prevMeasured instanceof CarbonImmutable || $measuredAt->greaterThan($prevMeasured);
                $newThroughput = $throughputAt instanceof CarbonImmutable
                    && (! $prevThroughput instanceof CarbonImmutable || $throughputAt->greaterThan($prevThroughput));

                if (! $newMeasurement && ! $newThroughput) {
                    continue;
                }

                $row = ['updated_at' => $now];

                if ($newMeasurement) {
                    $row += ['measured_at' => $this->stored($measuredAt), 'rtt_ms' => $rtt, 'error' => $error];
                    $this->addSample($server->id, $peer->id, $measuredAt, $rtt);
                }

                if ($newThroughput) {
                    $row += ['throughput_mbps' => $mbps, 'throughput_at' => $this->stored($throughputAt)];
                    $this->addThroughput($server->id, $peer->id, $throughputAt, $mbps);
                }

                if ($prev === null) {
                    DB::table('server_links')->insert(['server_id' => $server->id, 'peer_server_id' => $peer->id, ...$row]);
                } else {
                    DB::table('server_links')->where('server_id', $server->id)->where('peer_server_id', $peer->id)->update($row);
                }
            }
        });
    }

    /** One latency probe (a failure when $rtt is null) in its hour. */
    private function addSample(int $serverId, int $peerId, CarbonImmutable $at, ?float $rtt): void
    {
        DB::statement(
            'INSERT INTO server_link_samples (server_id, peer_server_id, at, samples, failures, rtt_sum_ms, rtt_min_ms, rtt_max_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (server_id, peer_server_id, at) DO UPDATE SET
               samples = server_link_samples.samples + EXCLUDED.samples,
               failures = server_link_samples.failures + EXCLUDED.failures,
               rtt_sum_ms = server_link_samples.rtt_sum_ms + EXCLUDED.rtt_sum_ms,
               rtt_min_ms = LEAST(server_link_samples.rtt_min_ms, EXCLUDED.rtt_min_ms),
               rtt_max_ms = GREATEST(server_link_samples.rtt_max_ms, EXCLUDED.rtt_max_ms)',
            [$serverId, $peerId, $at->startOfHour(), $rtt === null ? 0 : 1, $rtt === null ? 1 : 0, $rtt ?? 0, $rtt, $rtt],
        );
    }

    /** The latest throughput of its hour. */
    private function addThroughput(int $serverId, int $peerId, CarbonImmutable $at, float $mbps): void
    {
        DB::statement(
            'INSERT INTO server_link_samples (server_id, peer_server_id, at, throughput_mbps)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (server_id, peer_server_id, at) DO UPDATE SET throughput_mbps = EXCLUDED.throughput_mbps',
            [$serverId, $peerId, $at->startOfHour(), $mbps],
        );
    }

    /** Keeps the milliseconds: a repeated measurement must compare equal. */
    private function stored(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.vP');
    }

    private function time(mixed $value, CarbonImmutable $now): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $at = CarbonImmutable::parse($value)->utc();

        if ($at->greaterThan($now->addMinutes(5)) || $at->lessThan($now->subDay())) {
            return null;
        }

        return $at;
    }
}
