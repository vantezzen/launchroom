<?php

declare(strict_types=1);

namespace App\Queries\Commands;

use App\Data\Commands\CommandOutputLineData;
use App\Data\Commands\CommandOutputPageData;
use App\Models\Commands\CommandRun;
use App\Models\LogChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A command's output (D105): the log chunks its task shipped, in arrival
 * order, numbered from 1. Paginated forwards by cursor = id of the last
 * chunk returned.
 */
final class CommandRunOutput
{
    public const int PAGE_LINES = 5000;

    public function page(CommandRun $run, int $after = 0, int $maxLines = self::PAGE_LINES): CommandOutputPageData
    {
        if ($run->daemon_task_id === null) {
            return new CommandOutputPageData([], $after, false);
        }

        $number = $after > 0 ? (int) $this->chunks($run)->where('id', '<=', $after)->sum(DB::raw('jsonb_array_length(lines)')) : 0;
        $lines = [];
        $cursor = $after;

        $chunks = $this->chunks($run)->where('id', '>', $after)->orderBy('id')->limit(500)->get(['id', 'lines']);

        foreach ($chunks as $chunk) {
            /** @var list<array<string, mixed>> $raw */
            $raw = $chunk->lines;

            if ($lines !== [] && count($lines) + count($raw) > $maxLines) {
                break;
            }

            foreach ($raw as $line) {
                $lines[] = new CommandOutputLineData(
                    number: ++$number,
                    time: is_string($line['time'] ?? null) ? $line['time'] : '',
                    stream: is_string($line['stream'] ?? null) ? $line['stream'] : 'stdout',
                    text: is_string($line['text'] ?? null) ? $line['text'] : '',
                );
            }

            $cursor = $chunk->id;
        }

        return new CommandOutputPageData($lines, $cursor, $this->chunks($run)->where('id', '>', $cursor)->exists());
    }

    /**
     * @return Builder<LogChunk>
     */
    private function chunks(CommandRun $run): Builder
    {
        return LogChunk::query()->where('team_id', $run->team_id)->where('daemon_task_id', $run->daemon_task_id);
    }
}
