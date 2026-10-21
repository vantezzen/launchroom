<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\WalArchiveState;
use App\Models\Backups\WalArchive;
use App\Models\Placement;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores the WAL shipping state a server reports (Report.walArchives,
 * docs/14 §10.2) on the instance's WAL archive. Only workloads placed on the
 * reporting server count; observations only move forward (a restarted
 * launchroomd forgets when it last shipped, the dashboard does not).
 */
final readonly class RecordWalArchives
{
    /**
     * @param  list<array<string, mixed>>  $statuses
     */
    public function handle(Server $server, array $statuses): void
    {
        foreach ($statuses as $status) {
            $workload = is_string($status['workload'] ?? null) ? $status['workload'] : null;
            $state = WalArchiveState::tryFrom(is_string($status['state'] ?? null) ? $status['state'] : '');
            $checkedAt = $this->time($status['checkedAt'] ?? null);

            if ($workload === null || ! $state instanceof WalArchiveState || ! $checkedAt instanceof CarbonImmutable) {
                continue;
            }

            $instanceId = Placement::query()->where('server_id', $server->id)->where('workload_id', $workload)->value('service_instance_id');

            if (! is_int($instanceId)) {
                continue;
            }

            DB::transaction(function () use ($instanceId, $server, $status, $state, $checkedAt): void {
                $archive = WalArchive::query()->where('service_instance_id', $instanceId)->lockForUpdate()->first();

                if (! $archive instanceof WalArchive || ($archive->checked_at !== null && $archive->checked_at->gt($checkedAt))) {
                    return;
                }

                $archived = $this->time($status['lastArchivedAt'] ?? null);
                $segment = is_string($status['lastSegment'] ?? null) && preg_match('/^[0-9A-F]{24}$/D', $status['lastSegment']) === 1 ? $status['lastSegment'] : null;
                $message = is_string($status['message'] ?? null) ? mb_substr($status['message'], 0, 1024) : null;

                $archive->forceFill([
                    'state' => $state,
                    'checked_at' => $checkedAt,
                    'pending_segments' => max(0, is_int($status['pendingSegments'] ?? null) ? $status['pendingSegments'] : 0),
                    'message' => $state === WalArchiveState::Failing ? $message : null,
                    'reported_by_server_id' => $server->id,
                    'failing_since' => $state === WalArchiveState::Failing ? ($archive->failing_since ?? $checkedAt) : null,
                ]);

                if ($archived instanceof CarbonImmutable && ($archive->last_archived_at === null || $archived->gt($archive->last_archived_at))) {
                    $archive->forceFill(['last_archived_at' => $archived]);
                }

                if ($segment !== null && ($archive->last_segment === null || strcmp($segment, $archive->last_segment) > 0)) {
                    $archive->forceFill(['last_segment' => $segment]);
                }

                $archive->save();
            });
        }
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
