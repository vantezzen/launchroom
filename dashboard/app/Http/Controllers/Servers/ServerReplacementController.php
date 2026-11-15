<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\ReplaceServer;
use App\Data\Servers\ReplaceServerData;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Server;
use App\Models\User;
use App\Queries\ServerReplacementPage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Servers → (dead server) → Replace server (docs/14 §5.3): pick a
 * replacement, choose which stateful services to restore from their latest
 * backups, then follow the progress on the same page.
 */
final class ServerReplacementController extends Controller
{
    public function show(Server $server, #[CurrentUser] User $user, ServerReplacementPage $page): Response
    {
        Gate::authorize('replace', $server);

        return Inertia::render('servers/replace', $page->for($server, $user));
    }

    public function store(Request $request, Server $server, ReplaceServerData $data, #[CurrentUser] User $user, ReplaceServer $replace): RedirectResponse
    {
        Gate::authorize('replace', $server);
        $replacement = $server->onlineTeammate($data->replacement, 'replacement');
        $replace->handle($server, $replacement, $data->restore, $user, $this->auditActor($request, $user));

        Toast::success("{$replacement->name} is taking over from {$server->name}.");

        return to_route('servers.replace', $server);
    }
}
