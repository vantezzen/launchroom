<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes runtime log chunks past their retention (docs/08 §12), in batches
 * so no statement holds locks for long:
 *
 * - environments with runtime log retention: chunks older than its period,
 *   then the oldest beyond its size cap (bytes of the stored lines,
 *   uncompressed);
 * - everything else (live tails of environments without retention, runtime
 *   chunks that lost their environment): older than
 *   `launchroom.logs.tail_retention_hours`.
 *
 * Build and deploy logs are never touched here. Idempotent; run by
 * `launchroom:logs:prune` every 15 minutes.
 */
final readonly class PruneRuntimeLogs
{
    private const int BATCH = 5000;

    /**
     * @return int deleted chunks
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $deleted = 0;

        $retaining = Environment::query()
            ->whereNotNull('runtime_log_retention_days')
            ->where('runtime_log_retention_days', '>', 0)
            ->get(['id', 'runtime_log_retention_days', 'runtime_log_max_mb']);

        foreach ($retaining as $environment) {
            $cutoff = $now->subDays((int) $environment->runtime_log_retention_days);
            $deleted += $this->deleteWhere('environment_id = ? AND runtime AND created_at < ?', [$environment->id, $cutoff]);

            if ($environment->runtime_log_max_mb !== null && $environment->runtime_log_max_mb > 0) {
                $deleted += $this->trimToSize($environment->id, $environment->runtime_log_max_mb * 1024 * 1024);
            }
        }

        $tailCutoff = $now->subHours(config()->integer('launchroom.logs.tail_retention_hours'));
        $deleted += $this->deleteWhere(
            'runtime AND created_at < ? AND (environment_id IS NULL OR environment_id NOT IN (SELECT id FROM environments WHERE runtime_log_retention_days > 0))',
            [$tailCutoff],
        );

        return $deleted;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function deleteWhere(string $where, array $bindings): int
    {
        $total = 0;

        do {
            $n = DB::delete("DELETE FROM log_chunks WHERE id IN (SELECT id FROM log_chunks WHERE {$where} LIMIT ".self::BATCH.')', $bindings);
            $total += $n;
        } while ($n === self::BATCH);

        return $total;
    }

    /**
     * Keeps the newest runtime chunks of an environment whose bytes add up
     * to at most $cap; deletes the older ones.
     */
    private function trimToSize(int $environmentId, int $cap): int
    {
        $stored = (int) DB::table('log_chunks')->where('environment_id', $environmentId)->where('runtime', true)->sum('bytes');

        if ($stored <= $cap) {
            return 0;
        }

        $cutoff = DB::selectOne(<<<'SQL'
            SELECT created_at, id FROM (
              SELECT id, created_at, sum(bytes) OVER (ORDER BY created_at DESC, id DESC) AS kept
              FROM log_chunks WHERE environment_id = ? AND runtime
            ) t WHERE kept > ? ORDER BY created_at DESC, id DESC LIMIT 1
            SQL, [$environmentId, $cap]);

        $cutoff = is_object($cutoff) ? (array) $cutoff : [];

        if (! isset($cutoff['created_at'], $cutoff['id'])) {
            return 0;
        }

        return $this->deleteWhere('environment_id = ? AND runtime AND (created_at, id) <= (?, ?)', [$environmentId, $cutoff['created_at'], $cutoff['id']]);
    }
}
