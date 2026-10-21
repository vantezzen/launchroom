<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Actions\Instance\FinishRecovery;
use App\Actions\Instance\RebuildMissingImages;
use App\Actions\Servers\ReplaceServer;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Queries\RecoveryChecklist;
use App\Queries\ServerReplacementPlan;
use App\Services\Instance\RecoveryMode;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recovery mode after `install.sh --restore` (docs/14 §5.2), instance owners
 * only: the checklist, replacing the previous control-plane server with this
 * one, rebuilding images that are gone from the registry, and finishing.
 */
final class RecoveryController extends Controller
{
    public function show(Request $request, RecoveryChecklist $checklist): Response
    {
        Gate::authorize('manageInstance');

        return Inertia::render('recovery', $checklist->get($request->boolean('images')));
    }

    /**
     * a. Replace the previous control-plane server with this one, restoring
     *    every stateful service that has a backup.
     */
    public function replace(Request $request, #[CurrentUser] User $user, RecoveryChecklist $checklist, ServerReplacementPlan $plan, ReplaceServer $replace): RedirectResponse
    {
        Gate::authorize('manageInstance');
        abort_unless(RecoveryMode::active(), 409, 'Recovery mode is not active.');

        $previous = RecoveryMode::previousControlPlane();
        $current = $previous instanceof Server ? Server::query()->where('is_control_plane', true)->where('status', ServerStatus::Online->value)
            ->whereKeyNot($previous->id)
            ->orderByDesc('id')->first() : null;

        if (! $previous instanceof Server || ! $current instanceof Server) {
            return back()->withErrors(['replace' => 'This server’s launchroomd hasn’t enrolled yet, or there is no previous control-plane server to replace.']);
        }

        $restore = [];

        foreach ($plan->for($previous) as $entry) {
            if ($entry['stateful'] && $entry['restore'] !== null) {
                $restore[] = $entry['instance']->public_id;
            }
        }

        $replace->handle($previous, $current, $restore, $user, $this->auditActor($request, $user), recovery: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$current->name} took over from {$previous->name}."]);

        return to_route('recovery.show');
    }

    /**
     * e. Rebuild images that are gone from the registry.
     */
    public function rebuild(Request $request, #[CurrentUser] User $user, RebuildMissingImages $rebuild): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = $rebuild->handle($user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => $result['failed'] === [] ? 'success' : 'error', 'message' => $result['failed'] === []
            ? "Rebuilding {$result['started']} services from their recorded commits."
            : "Rebuilding {$result['started']} services; ".count($result['failed']).' could not start: '.implode(' ', $result['failed'])]);

        return to_route('recovery.show');
    }

    public function finish(Request $request, #[CurrentUser] User $user, FinishRecovery $finish): RedirectResponse
    {
        Gate::authorize('manageInstance');

        /** @var list<string> $open */
        $open = array_values(array_filter((array) $request->input('open', []), is_string(...)));
        $finish->handle(array_slice($open, 0, 10), $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recovery finished.']);

        return to_route('servers.index');
    }
}
