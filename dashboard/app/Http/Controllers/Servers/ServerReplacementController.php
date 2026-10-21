<?php

declare(strict_types=1);

namespace App\Http\Controllers\Servers;

use App\Actions\Servers\ReplaceServer;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
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

    public function store(Request $request, Server $server, #[CurrentUser] User $user, ReplaceServer $replace): RedirectResponse
    {
        Gate::authorize('replace', $server);

        $request->validate([
            'replacement' => ['required', 'string', 'max:40'],
            'restore' => ['array', 'max:500'],
            'restore.*' => ['string', 'max:40'],
        ]);

        $replacement = Server::query()
            ->where('team_id', $server->team_id)
            ->where('public_id', $request->string('replacement')->toString())
            ->where('status', ServerStatus::Online->value)
            ->first();

        if (! $replacement instanceof Server) {
            return back()->withErrors(['replacement' => 'Pick an online server of this team.']);
        }

        /** @var list<string> $restore */
        $restore = array_values(array_filter((array) $request->input('restore', []), is_string(...)));
        $replace->handle($server, $replacement, $restore, $user, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$replacement->name} is taking over from {$server->name}."]);

        return to_route('servers.replace', $server);
    }
}
