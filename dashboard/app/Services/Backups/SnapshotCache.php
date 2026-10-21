<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Enums\DaemonTaskStatus;
use App\Models\DaemonTask;
use App\Models\ServiceInstance;
use Illuminate\Support\Facades\Cache;

/**
 * Snapshot listings from the repository (`restic snapshots --json` via a
 * `backup_snapshots` task), cached briefly per database (docs/14 §7:
 * snapshots are not mirrored into the database; the repository is the
 * source of truth). Values are snapshot metadata only, never secrets.
 */
final class SnapshotCache
{
    /**
     * @return array{status: 'pending'|'ready'|'failed', fetchedAt: string|null, error: string|null, snapshots: list<array{id: string, time: string, tags: list<string>, sizeBytes: int|null}>}|null
     */
    public function forInstance(ServiceInstance $instance): ?array
    {
        $value = Cache::get($this->key($instance->id));

        return is_array($value) ? $this->normalize($value) : null;
    }

    public function pending(ServiceInstance $instance, DaemonTask $task): void
    {
        Cache::put($this->taskKey($task->id), $instance->id, $this->ttl());
        $previous = $this->forInstance($instance);
        Cache::put($this->key($instance->id), [
            'status' => 'pending',
            'fetchedAt' => $previous['fetchedAt'] ?? null,
            'error' => null,
            'snapshots' => $previous['snapshots'] ?? [],
        ], $this->ttl());
    }

    public function storeFromTask(DaemonTask $task): void
    {
        $instanceId = Cache::get($this->taskKey($task->id));

        if (! is_int($instanceId) || ! $task->status->isTerminal()) {
            return;
        }

        $previous = Cache::get($this->key($instanceId));
        $previous = is_array($previous) ? $this->normalize($previous) : null;

        if ($task->status !== DaemonTaskStatus::Succeeded) {
            Cache::put($this->key($instanceId), [
                'status' => 'failed',
                'fetchedAt' => $previous['fetchedAt'] ?? null,
                'error' => $task->error ?? 'Listing snapshots failed.',
                'snapshots' => $previous['snapshots'] ?? [],
            ], $this->ttl());

            return;
        }

        $result = $task->result['snapshots'] ?? null;
        $list = is_array($result) && is_array($result['snapshots'] ?? null) ? $result['snapshots'] : [];
        $snapshots = [];

        foreach ($list as $snapshot) {
            if (! is_array($snapshot) || ! is_string($snapshot['id'] ?? null) || ! is_string($snapshot['time'] ?? null)) {
                continue;
            }

            $snapshots[] = [
                'id' => $snapshot['id'],
                'time' => $snapshot['time'],
                'tags' => array_values(array_filter(is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [], is_string(...))),
                'sizeBytes' => is_int($snapshot['sizeBytes'] ?? null) ? $snapshot['sizeBytes'] : null,
            ];
        }

        Cache::put($this->key($instanceId), [
            'status' => 'ready',
            'fetchedAt' => now()->utc()->toIso8601String(),
            'error' => null,
            'snapshots' => $snapshots,
        ], $this->ttl());
    }

    /**
     * @param  array<mixed>  $value
     * @return array{status: 'pending'|'ready'|'failed', fetchedAt: string|null, error: string|null, snapshots: list<array{id: string, time: string, tags: list<string>, sizeBytes: int|null}>}
     */
    private function normalize(array $value): array
    {
        $status = in_array($value['status'] ?? null, ['pending', 'ready', 'failed'], true) ? $value['status'] : 'failed';
        $snapshots = [];

        foreach (is_array($value['snapshots'] ?? null) ? $value['snapshots'] : [] as $snapshot) {
            if (is_array($snapshot) && is_string($snapshot['id'] ?? null) && is_string($snapshot['time'] ?? null)) {
                $snapshots[] = [
                    'id' => $snapshot['id'],
                    'time' => $snapshot['time'],
                    'tags' => array_values(array_filter(is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [], is_string(...))),
                    'sizeBytes' => is_int($snapshot['sizeBytes'] ?? null) ? $snapshot['sizeBytes'] : null,
                ];
            }
        }

        return [
            'status' => $status,
            'fetchedAt' => is_string($value['fetchedAt'] ?? null) ? $value['fetchedAt'] : null,
            'error' => is_string($value['error'] ?? null) ? $value['error'] : null,
            'snapshots' => $snapshots,
        ];
    }

    private function key(int $instanceId): string
    {
        return "launchroom:backups:snapshots:{$instanceId}";
    }

    private function taskKey(int $taskId): string
    {
        return "launchroom:backups:snapshot-task:{$taskId}";
    }

    private function ttl(): int
    {
        return config()->integer('launchroom.backups.snapshot_cache_seconds');
    }
}
