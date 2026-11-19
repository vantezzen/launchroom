<?php

declare(strict_types=1);

namespace App\Queries\Backups;

use App\Data\Backups\BackupRunProgressData;
use App\Enums\Backups\BackupRunPhase;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\LogSourceKind;
use App\Models\Backups\BackupRun;
use App\Models\LogChunk;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * What a backup in flight is doing (the Backups page's running rows and
 * the service Backups tab). launchroomd logs a machine-readable line
 * (`::progress:: {json}`, op `backup`) when a phase starts and every few
 * seconds while it streams; the newest one of each run's task is read from
 * its task log. Older servers log only human-readable lines, which give
 * the phase without counters. The expected size is restic's own total when
 * it reports one, else the last successful size of the same item;
 * percentages are never invented. Two queries for any number of runs,
 * plus one for the sizes and durations of earlier runs.
 */
final class BackupProgress
{
    public const string PREFIX = '::progress:: ';

    private const int HISTORY = 10;

    /**
     * @param  iterable<int, BackupRun>  $runs  in flight; server loaded for the waiting line
     * @return array<int, BackupRunProgressData> run id => progress
     */
    public function for(iterable $runs): array
    {
        /** @var Collection<int, BackupRun> $runs */
        $runs = new Collection($runs)->reject(static fn (BackupRun $run): bool => $run->status->isTerminal())->values();

        if ($runs->isEmpty()) {
            return [];
        }

        $tasks = [];

        foreach ($runs as $run) {
            if ($run->daemon_task_id !== null) {
                $tasks[$run->daemon_task_id] = $run->daemon_task_id;
            }
        }

        $lines = $this->latestLines(array_values($tasks));
        $history = $this->history($runs);
        // What holds each server's slot: the running run on it (one heavy task per server).
        $busy = BackupRun::query()
            ->whereIn('server_id', $runs->pluck('server_id')->filter()->unique()->values()->all())
            ->where('status', BackupRunStatus::Running->value)
            ->with(['serviceInstance.service', 'volume'])
            ->get();
        $out = [];

        foreach ($runs as $run) {
            $parsed = $run->daemon_task_id === null ? null : ($lines[$run->daemon_task_id] ?? null);
            [$expected, $usual] = $history[$this->itemKey($run)] ?? [null, null];
            $phase = $this->phase($run, $parsed);
            $total = is_int($parsed['totalBytes'] ?? null) && $parsed['totalBytes'] > 0 ? $parsed['totalBytes'] : null;
            $waiting = $phase === BackupRunPhase::Queued || $phase === BackupRunPhase::Waiting;
            $holder = $waiting ? $busy->first(static fn (BackupRun $other): bool => $other->server_id === $run->server_id && $other->id !== $run->id) : null;

            $out[$run->id] = new BackupRunProgressData(
                runId: $run->public_id,
                state: $run->status === BackupRunStatus::Running && ! $waiting ? 'running' : 'queued',
                phase: $phase,
                waitingFor: $waiting ? $run->server?->name : null,
                waitingBehind: $holder instanceof BackupRun ? ($holder->volume->mount_path ?? $holder->serviceInstance?->service->name) : null,
                bytesDone: is_int($parsed['bytes'] ?? null) ? $parsed['bytes'] : null,
                bytesExpected: $total ?? $expected,
                expectedIsEstimate: $total === null && $expected !== null,
                percent: is_float($parsed['percent'] ?? null) || is_int($parsed['percent'] ?? null) ? (float) $parsed['percent'] : null,
                queuedAt: $run->created_at,
                startedAt: $run->started_at,
                elapsedMs: is_int($parsed['elapsedMs'] ?? null) ? $parsed['elapsedMs'] : null,
                usualDurationMs: $usual,
                reason: $run->reason,
            );
        }

        return $out;
    }

    /**
     * The newest progress state per task: the last `::progress::` line of
     * op backup in its newest log chunk, else the phase its last
     * human-readable line implies.
     *
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>> task id => parsed line
     */
    private function latestLines(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $newest = LogChunk::query()
            ->whereIn('daemon_task_id', $taskIds)
            ->where('source_kind', LogSourceKind::Task->value)
            ->selectRaw('max(id) as id')
            ->groupBy('daemon_task_id');
        $chunks = LogChunk::query()->whereIn('id', $newest)->get(['id', 'daemon_task_id', 'lines']);
        $out = [];

        foreach ($chunks as $chunk) {
            $parsed = self::parse($chunk->lines);

            if ($parsed !== null && $chunk->daemon_task_id !== null) {
                $out[$chunk->daemon_task_id] = $parsed;
            }
        }

        return $out;
    }

    /**
     * The newest state a list of task log lines shows, newest line first.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    public static function parse(array $lines): ?array
    {
        foreach (array_reverse($lines) as $line) {
            $text = is_string($line['text'] ?? null) ? $line['text'] : '';

            if (str_starts_with($text, self::PREFIX)) {
                $json = json_decode(substr($text, strlen(self::PREFIX)), true);

                if (is_array($json) && ($json['v'] ?? null) === 1 && ($json['op'] ?? 'backup') === 'backup' && is_string($json['phase'] ?? null)) {
                    /** @var array<string, mixed> $json */
                    return $json;
                }

                continue;
            }

            $phase = match (true) {
                str_starts_with($text, 'Waiting for another') => 'waiting',
                str_starts_with($text, 'Streaming ') => 'streaming',
                str_starts_with($text, 'Snapshot ') && str_contains($text, ' stored') => 'stored',
                str_starts_with($text, 'Initialised a new backup repository') => 'preparing',
                default => null,
            };

            if ($phase !== null) {
                return ['phase' => $phase];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $parsed
     */
    private function phase(BackupRun $run, ?array $parsed): BackupRunPhase
    {
        // launchroom's own backup runs in the dashboard: no task log.
        if ($run->subject === BackupSubject::ControlPlane) {
            return $run->status === BackupRunStatus::Running ? BackupRunPhase::Streaming : BackupRunPhase::Queued;
        }

        $phase = is_string($parsed['phase'] ?? null) ? BackupRunPhase::tryFrom($parsed['phase']) : null;

        return $phase ?? ($run->status === BackupRunStatus::Running ? BackupRunPhase::Preparing : BackupRunPhase::Queued);
    }

    private function itemKey(BackupRun $run): string
    {
        return $run->subject === BackupSubject::ControlPlane ? 'control_plane' : $run->service_instance_id.'|'.$run->subject->value.'|'.($run->volume_id ?? 0);
    }

    /**
     * Per item: the last successful size and the median duration of the
     * last successful runs.
     *
     * @param  Collection<int, BackupRun>  $runs
     * @return array<string, array{int|null, int|null}>
     */
    private function history(Collection $runs): array
    {
        $instances = $runs->pluck('service_instance_id')->filter()->unique()->values()->all();
        $controlPlane = $runs->contains(static fn (BackupRun $run): bool => $run->subject === BackupSubject::ControlPlane);

        $done = BackupRun::query()
            ->where('status', BackupRunStatus::Succeeded->value)
            ->where(static function (Builder $query) use ($instances, $controlPlane): void {
                $query->whereIn('service_instance_id', $instances);

                if ($controlPlane) {
                    $query->orWhere('subject', BackupSubject::ControlPlane->value);
                }
            })
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(60))
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'subject', 'service_instance_id', 'volume_id', 'size_bytes', 'duration_ms', 'started_at', 'finished_at']);

        $out = [];

        foreach ($done->groupBy(fn (BackupRun $run): string => $this->itemKey($run)) as $key => $items) {
            $recent = $items->take(self::HISTORY);
            $durations = $recent
                ->map(static fn (BackupRun $run): ?int => $run->duration_ms ?? ($run->started_at !== null && $run->finished_at !== null ? (int) $run->started_at->diffInMilliseconds($run->finished_at) : null))
                ->filter(static fn (?int $ms): bool => $ms !== null && $ms > 0)
                ->sort()
                ->values();
            $median = $durations->isEmpty() ? null : (int) $durations[intdiv($durations->count(), 2)];
            $size = $recent->first()?->size_bytes;

            $out[(string) $key] = [$size, $median];
        }

        return $out;
    }
}
