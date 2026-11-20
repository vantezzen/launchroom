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
use App\Actions\Moves\StopMoveItem;
use App\Data\Moves\ApproveMoveData;
use App\Data\Moves\MoveWorkloadsData;
use App\Data\Moves\StartHandoverData;
use App\Enums\ToastType;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
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

    public function approve(Request $request, ApproveMoveData $data, #[CurrentUser] User $user, ApproveControlPlaneMove $approve): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $approve->handle($this->open(), $data->code, $user, $this->auditActor($request, $user));
        Toast::success('The new server is joining.');

        return to_route('move.edit');
    }

    public function workloads(Request $request, MoveWorkloadsData $data, #[CurrentUser] User $user, MoveControlPlaneWorkloads $move): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $move->handle($this->open(), $data->backupFirst, $user, $this->auditActor($request, $user));

        return to_route('move.edit');
    }

    public function retry(Request $request, ServerMoveItem $item, #[CurrentUser] User $user, RetryMoveItem $retry): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $serverMove = $this->open()->serverMove;
        abort_unless($serverMove instanceof ServerMove, 404);
        $retry->handle($serverMove, RouteScope::moveItem($serverMove, $item), $this->auditActor($request, $user));

        return to_route('move.edit');
    }

    public function stop(Request $request, ServerMoveItem $item, #[CurrentUser] User $user, StopMoveItem $stop): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $serverMove = $this->open()->serverMove;
        abort_unless($serverMove instanceof ServerMove, 404);
        $stopped = $stop->handle($serverMove, RouteScope::moveItem($serverMove, $item), $user, $this->auditActor($request, $user));
        Toast::success("{$stopped->serviceInstance->service->name} was stopped and stays stopped.");

        return to_route('move.edit');
    }

    public function handover(Request $request, StartHandoverData $data, #[CurrentUser] User $user, StartHandover $handover): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $handover->handle($this->open(), $data->confirmation, $this->auditActor($request, $user));

        return to_route('move.edit');
    }

    public function abort(Request $request, #[CurrentUser] User $user, AbortHandover $abort): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $abort->handle($this->open(), 'aborted from the dashboard', $this->auditActor($request, $user));
        Toast::success('The hand-over was cancelled. You can make changes in this dashboard again.');

        return to_route('move.edit');
    }

    public function cancel(Request $request, #[CurrentUser] User $user, CancelControlPlaneMove $cancel): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $cancel->handle($this->open(), $this->auditActor($request, $user));
        Toast::success('The move was cancelled.');

        return to_route('move.edit');
    }

    public function rebuild(Request $request, #[CurrentUser] User $user, RebuildMissingImages $rebuild): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $result = $rebuild->handle($user, $this->auditActor($request, $user));
        Toast::flash($result['failed'] === [] ? ToastType::Success : ToastType::Error, $result['failed'] === []
            ? "Rebuilding {$result['started']} apps from their recorded commits."
            : "Rebuilding {$result['started']} apps; ".count($result['failed']).' could not start: '.implode(' ', $result['failed']));

        return to_route('move.edit');
    }

    public function finish(Request $request, #[CurrentUser] User $user, FinishMoveChecklist $finish): RedirectResponse
    {
        Gate::authorize('manageInstance');
        $finish->handle($this->auditActor($request, $user));
        Toast::success('Move finished.');

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
