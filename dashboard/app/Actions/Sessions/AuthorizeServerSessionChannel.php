<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Broadcasting\Channel;
use App\Enums\DaemonTaskStatus;
use App\Enums\TaskBackedAccessStatus;
use App\Models\Server;
use App\Models\Sessions\InteractiveSession;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Whether a server may join `private-session.<ses>` (docs/03 §11.2): only an
 * open session whose task this server holds right now. The first join is
 * stamped (`server_joined_at`), so a session that never started can tell
 * whether the server or the browser didn't arrive.
 */
final readonly class AuthorizeServerSessionChannel
{
    public function handle(Server $server, string $channel): bool
    {
        $id = Channel::sessionFromWire($channel);

        if ($id === null) {
            return false;
        }

        $session = InteractiveSession::query()
            ->where('public_id', $id)
            ->where('server_id', $server->id)
            ->where('status', TaskBackedAccessStatus::Open->value)
            ->whereHas('daemonTask', static fn (Builder $query) => $query
                ->where('server_id', $server->id)
                ->where('status', DaemonTaskStatus::Leased->value))
            ->first();

        if (! $session instanceof InteractiveSession) {
            return false;
        }

        InteractiveSession::query()
            ->whereKey($session->id)
            ->whereNull('server_joined_at')
            ->update(['server_joined_at' => now()]);

        return true;
    }
}
