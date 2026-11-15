<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Actions\Instance\FinishRecovery;
use App\Actions\Instance\RebuildMissingImages;
use App\Actions\Instance\ReplacePreviousControlPlane;
use App\Enums\ToastType;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\User;
use App\Queries\RecoveryChecklist;
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

    public function replace(Request $request, #[CurrentUser] User $user, ReplacePreviousControlPlane $replace): RedirectResponse
    {
        Gate::authorize('manageInstance');
        abort_unless(RecoveryMode::active(), 409, 'Recovery mode is not active.');

        $replacement = $replace->handle($user, $this->auditActor($request, $user));
        Toast::success("{$replacement->newServer->name} took over from {$replacement->oldServer->name}.");

        return to_route('recovery.show');
    }

    public function rebuild(Request $request, #[CurrentUser] User $user, RebuildMissingImages $rebuild): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $result = $rebuild->handle($user, $this->auditActor($request, $user));
        Toast::flash($result['failed'] === [] ? ToastType::Success : ToastType::Error, $result['failed'] === []
            ? "Rebuilding {$result['started']} services from their recorded commits."
            : "Rebuilding {$result['started']} services; ".count($result['failed']).' could not start: '.implode(' ', $result['failed']));

        return to_route('recovery.show');
    }

    public function finish(Request $request, #[CurrentUser] User $user, FinishRecovery $finish): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $finish->handle($this->auditActor($request, $user));
        Toast::success('Recovery finished.');

        return to_route('servers.index');
    }
}
