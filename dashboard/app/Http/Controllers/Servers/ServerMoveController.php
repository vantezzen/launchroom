<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Moves\CancelServerMove;
use App\Actions\Moves\RetireMovedServer;
use App\Actions\Moves\RetryMoveItem;
use App\Actions\Moves\StartServerMove;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\Server;
use App\Models\User;
use App\Queries\ServerMovePage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Servers → (server) → Move to another server (docs/14 §13.2): pick the
 * target, review the plan, start; then follow each service, retry a failed
 * one, cancel, and retire the source once DNS points at the target.
 */
final class ServerMoveController extends Controller
{
    public function show(Request $request, Server $server, #[CurrentUser] User $user, ServerMovePage $page): Response
    {
        Gate::authorize('move', $server);
        $request->validate(['target' => ['nullable', 'string', 'max:40']]);

        return Inertia::render('servers/move', $page->for($server, $user, $request->filled('target') ? $request->string('target')->toString() : null));
    }

    public function store(Request $request, Server $server, #[CurrentUser] User $user, StartServerMove $start): RedirectResponse
    {
        Gate::authorize('move', $server);

        $request->validate([
            'target' => ['required', 'string', 'max:40'],
            'backupFirst' => ['boolean'],
            'confirmation' => ['required', 'string', 'max:64'],
        ]);

        if (! hash_equals($server->name, $request->string('confirmation')->toString())) {
            return back()->withErrors(['confirmation' => "Type {$server->name} to confirm."]);
        }

        $target = Server::query()
            ->where('team_id', $server->team_id)
            ->where('public_id', $request->string('target')->toString())
            ->where('status', ServerStatus::Online->value)
            ->first();

        if (! $target instanceof Server) {
            return back()->withErrors(['target' => 'Pick an online server of this team.']);
        }

        $start->handle($server, $target, $request->boolean('backupFirst'), $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Moving {$server->name} to {$target->name}."]);

        return to_route('servers.move', $server);
    }

    public function retry(Request $request, Server $server, string $move, string $item, #[CurrentUser] User $user, RetryMoveItem $retry): RedirectResponse
    {
        Gate::authorize('move', $server);
        [$found, $entry] = $this->item($server, $move, $item);
        $retry->handle($found, $entry, $this->auditActor($request, $user));

        return to_route('servers.move', $server);
    }

    public function cancel(Request $request, Server $server, string $move, #[CurrentUser] User $user, CancelServerMove $cancel): RedirectResponse
    {
        Gate::authorize('move', $server);
        $cancel->handle($this->move($server, $move), $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cancelling the move. Services that already moved stay on the new server.']);

        return to_route('servers.move', $server);
    }

    public function retire(Request $request, Server $server, string $move, #[CurrentUser] User $user, RetireMovedServer $retire): RedirectResponse
    {
        Gate::authorize('move', $server);
        $request->validate(['confirmation' => ['required', 'string', 'max:64']]);

        if (! hash_equals($server->name, $request->string('confirmation')->toString())) {
            return back()->withErrors(['confirmation' => "Type {$server->name} to confirm."]);
        }

        $found = $retire->handle($this->move($server, $move), $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$server->name} is retired. {$found->target->name} took its place."]);

        return to_route('servers.move', $server);
    }

    private function move(Server $server, string $publicId): ServerMove
    {
        $move = ServerMove::query()->where('source_server_id', $server->id)->where('team_id', $server->team_id)->where('public_id', $publicId)->first();
        abort_unless($move instanceof ServerMove, 404);

        return $move;
    }

    /**
     * @return array{ServerMove, ServerMoveItem}
     */
    private function item(Server $server, string $move, string $item): array
    {
        $found = $this->move($server, $move);
        $entry = ServerMoveItem::query()->where('server_move_id', $found->id)->where('public_id', $item)->first();
        abort_unless($entry instanceof ServerMoveItem, 404);

        return [$found, $entry];
    }
}
