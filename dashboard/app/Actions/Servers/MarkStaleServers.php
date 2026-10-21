<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Marks servers that stopped polling as unreachable, then offline
 * (docs/03 §9, hysteresis of two missed state polls). Scheduled every minute.
 */
final class MarkStaleServers
{
    public function handle(): int
    {
        $changed = 0;

        $rules = [
            [ServerStatus::Online, ServerStatus::Unreachable, config()->integer('launchroom.daemon.unreachable_after_seconds')],
            [ServerStatus::Unreachable, ServerStatus::Offline, config()->integer('launchroom.daemon.offline_after_seconds')],
        ];

        foreach ($rules as [$from, $to, $seconds]) {
            $ids = Server::query()
                ->where('status', $from->value)
                ->where(fn (Builder $query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds($seconds)))
                ->pluck('id');

            foreach ($ids as $id) {
                $changed += DB::transaction(function () use ($id, $from, $to): int {
                    $server = Server::query()->whereKey($id)->lockForUpdate()->first();

                    if ($server === null || $server->status !== $from) {
                        return 0;
                    }

                    $server->transitionTo($to);

                    return 1;
                });
            }
        }

        return $changed;
    }
}
