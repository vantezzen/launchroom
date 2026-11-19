<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Models\Team;
use Illuminate\Support\Facades\Cache;

/**
 * The newest "Back up everything now" of a team (its runs carry the batch
 * id in `backup_runs.batch_id`): when it started and which items couldn't
 * start, for the Backups page's progress line. Kept for a few hours.
 */
final class BackupBatches
{
    private const string KEY = 'launchroom:backups:batch:';

    private const int KEEP_SECONDS = 6 * 3600;

    /**
     * @param  list<array{name: string, message: string}>  $refused
     */
    public static function remember(Team $team, string $batch, array $refused): void
    {
        Cache::put(self::KEY.$team->id, ['id' => $batch, 'at' => now()->getTimestamp(), 'refused' => $refused], self::KEEP_SECONDS);
    }

    /**
     * @return array{id: string, at: int, refused: list<array{name: string, message: string}>}|null
     */
    public static function latest(Team $team): ?array
    {
        $value = Cache::get(self::KEY.$team->id);

        if (! is_array($value) || ! is_string($value['id'] ?? null) || ! is_int($value['at'] ?? null)) {
            return null;
        }

        $refused = [];

        foreach (is_array($value['refused'] ?? null) ? $value['refused'] : [] as $item) {
            if (is_array($item) && is_string($item['name'] ?? null) && is_string($item['message'] ?? null)) {
                $refused[] = ['name' => $item['name'], 'message' => $item['message']];
            }
        }

        return ['id' => $value['id'], 'at' => $value['at'], 'refused' => $refused];
    }
}
