<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Moves\CancelServerMove;
use App\Actions\Moves\RetireMovedServer;
use App\Actions\Moves\RetryMoveItem;
use App\Actions\Moves\StartServerMove;
use App\Actions\Moves\StopMoveItem;
use App\Data\Moves\RetireMovedServerData;
use App\Data\Moves\ServerMoveTargetData;
use App\Data\Moves\StartServerMoveData;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
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
 * one or leave it stopped, cancel, and retire the source once DNS points at the target.
 */
final class ServerMoveController extends Controller
{
    public function show(Server $server, ServerMoveTargetData $data, #[CurrentUser] User $user, ServerMovePage $page): Response
    {
        Gate::authorize('move', $server);

        return Inertia::render('servers/move', $page->for($server, $user, $data->target));
    }

    public function store(Request $request, Server $server, StartServerMoveData $data, #[CurrentUser] User $user, StartServerMove $start): RedirectResponse
    {
        Gate::authorize('move', $server);
        $target = $server->onlineTeammate($data->target, 'target');

        $start->handle($server, $target, $data->backupFirst, $user, $this->auditActor($request, $user), confirmation: $data->confirmation);
        Toast::success("Moving {$server->name} to {$target->name}.");

        return to_route('servers.move', $server);
    }

    public function retry(Request $request, Server $server, ServerMove $move, ServerMoveItem $item, #[CurrentUser] User $user, RetryMoveItem $retry): RedirectResponse
    {
        Gate::authorize('move', $server);
        $move = RouteScope::move($server, $move);
        $retry->handle($move, RouteScope::moveItem($move, $item), $this->auditActor($request, $user));

        return to_route('servers.move', $server);
    }

    public function stop(Request $request, Server $server, ServerMove $move, ServerMoveItem $item, #[CurrentUser] User $user, StopMoveItem $stop): RedirectResponse
    {
        Gate::authorize('move', $server);
        $move = RouteScope::move($server, $move);
        $stopped = $stop->handle($move, RouteScope::moveItem($move, $item), $user, $this->auditActor($request, $user));
        Toast::success("{$stopped->serviceInstance->service->name} was stopped and stays stopped.");

        return to_route('servers.move', $server);
    }

    public function cancel(Request $request, Server $server, ServerMove $move, #[CurrentUser] User $user, CancelServerMove $cancel): RedirectResponse
    {
        Gate::authorize('move', $server);
        $cancel->handle(RouteScope::move($server, $move), $this->auditActor($request, $user));
        Toast::success('Cancelling the move. Services that already moved stay on the new server.');

        return to_route('servers.move', $server);
    }

    public function retire(Request $request, Server $server, ServerMove $move, RetireMovedServerData $data, #[CurrentUser] User $user, RetireMovedServer $retire): RedirectResponse
    {
        Gate::authorize('move', $server);
        $found = $retire->handle(RouteScope::move($server, $move), $data->confirmation, $this->auditActor($request, $user));
        Toast::success("{$server->name} is retired. {$found->target->name} took its place.");

        return to_route('servers.move', $server);
    }
}
