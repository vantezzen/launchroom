<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Instance\RebuildMissingImages;
use App\Actions\Moves\AbortHandover;
use App\Actions\Moves\AdvanceControlPlaneMoves;
use App\Actions\Moves\ApproveControlPlaneMove;
use App\Actions\Moves\CancelControlPlaneMove;
use App\Actions\Moves\FinishMoveChecklist;
use App\Actions\Moves\MoveControlPlaneWorkloads;
use App\Actions\Moves\RetryMoveItem;
use App\Actions\Moves\StartControlPlaneMove;
use App\Actions\Moves\StartHandover;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\User;
use App\Queries\ControlPlaneMovePage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Move launchroom (docs/14 §13.4), instance owners only: the
 * pre-flight and the move command, confirming the new server's code,
 * moving the apps and data, the hand-over, and on the new dashboard the
 * checklist after the move. Changes authorize the instance owner.
 */
final class ControlPlaneMoveController extends Controller
{
    public function edit(Request $request, AdvanceControlPlaneMoves $advance, ControlPlaneMovePage $page): Response
    {
        Gate::authorize('manageInstance');
        $advance->handle();
        $token = $request->session()->get('moveToken');

        return Inertia::render('settings/move', $page->get(is_string($token) ? $token : null, $request->boolean('check')));
    }

    public function start(Request $request, #[CurrentUser] User $user, StartControlPlaneMove $start): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $started = $start->handle($user, $this->auditActor($request, $user));

        // Shown once on the next page (the token is never stored readable).
        return to_route('move.edit')->with('moveToken', $started['token']);
    }

    public function approve(Request $request, #[CurrentUser] User $user, ApproveControlPlaneMove $approve): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $approve->handle($this->open(), $request->string('code')->toString(), $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The new server is joining.']);

        return to_route('move.edit');
    }

    public function workloads(Request $request, #[CurrentUser] User $user, MoveControlPlaneWorkloads $move): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $request->validate(['backupFirst' => ['boolean']]);
        $move->handle($this->open(), $request->boolean('backupFirst'), $user, $this->auditActor($request, $user));

        return to_route('move.edit');
    }

    public function retry(Request $request, string $item, #[CurrentUser] User $user, RetryMoveItem $retry): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $open = $this->open();
        $serverMove = ServerMove::query()->where('public_id', (string) $open->serverMove?->public_id)->first();
        $entry = $serverMove instanceof ServerMove ? ServerMoveItem::query()->where('server_move_id', $serverMove->id)->where('public_id', $item)->first() : null;
        abort_unless($serverMove instanceof ServerMove && $entry instanceof ServerMoveItem, 404);
        $retry->handle($serverMove, $entry, $this->auditActor($request, $user));

        return to_route('move.edit');
    }

    public function handover(Request $request, #[CurrentUser] User $user, StartHandover $handover): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $request->validate(['confirmation' => ['required', 'string', 'max:32']]);

        if (strtolower(trim($request->string('confirmation')->toString())) !== 'hand over') {
            return back()->withErrors(['confirmation' => 'Type “hand over” to confirm.']);
        }

        $handover->handle($this->open(), $this->auditActor($request, $user));

        return to_route('move.edit');
    }

    public function abort(Request $request, #[CurrentUser] User $user, AbortHandover $abort): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $abort->handle($this->open(), 'aborted from the dashboard', $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The hand-over was cancelled. You can make changes in this dashboard again.']);

        return to_route('move.edit');
    }

    public function cancel(Request $request, #[CurrentUser] User $user, CancelControlPlaneMove $cancel): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $cancel->handle($this->open(), $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The move was cancelled.']);

        return to_route('move.edit');
    }

    public function rebuild(Request $request, #[CurrentUser] User $user, RebuildMissingImages $rebuild): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $result = $rebuild->handle($user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => $result['failed'] === [] ? 'success' : 'error', 'message' => $result['failed'] === []
            ? "Rebuilding {$result['started']} apps from their recorded commits."
            : "Rebuilding {$result['started']} apps; ".count($result['failed']).' could not start: '.implode(' ', $result['failed'])]);

        return to_route('move.edit');
    }

    public function finish(Request $request, #[CurrentUser] User $user, FinishMoveChecklist $finish): RedirectResponse
    {
        Gate::authorize('manageInstance');
        /** @var list<string> $open */
        $open = array_values(array_filter((array) $request->input('open', []), is_string(...)));
        $finish->handle(array_slice($open, 0, 10), $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Move finished.']);

        return to_route('servers.index');
    }

    private function open(): ControlPlaneMove
    {
        $move = ControlPlaneMove::open();

        if (! $move instanceof ControlPlaneMove) {
            throw DomainRuleViolation::conflict('No move of launchroom is open.');
        }

        return $move;
    }
}
