<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\RetryFinalBackup;
use App\Actions\Backups\SkipFinalBackup;
use App\Enums\Backups\DeletionOutcome;
use App\Http\Controllers\Controller;
use App\Models\Backups\DeletionRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Retry a blocked final backup, or delete without one (docs/14 §9.13).
 * Requests resolve through the user's teams only.
 */
final class DeletionRequestController extends Controller
{
    public function retry(Request $request, string $deletion, #[CurrentUser] User $user, RetryFinalBackup $retry): RedirectResponse
    {
        $deletionRequest = $this->requestFor($deletion, $user);
        Gate::authorize('retry', $deletionRequest);

        $retry->handle($deletionRequest, $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Taking the final backup again.']);

        return back();
    }

    public function skip(Request $request, string $deletion, #[CurrentUser] User $user, SkipFinalBackup $skip): RedirectResponse
    {
        $deletionRequest = $this->requestFor($deletion, $user);
        Gate::authorize('skipFinalBackup', $deletionRequest);

        $outcome = $skip->handle($deletionRequest, $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $outcome === DeletionOutcome::Deleted => "{$deletionRequest->subject_name} deleted without a final backup.",
            $deletionRequest->subject_type === DeletionRequest::SERVICE_INSTANCE => 'The removal no longer waits for a final backup. Deploy to apply it.',
            default => 'The deletion no longer waits for a final backup.',
        }]);

        if ($outcome === DeletionOutcome::Deleted && $deletionRequest->subject_type === DeletionRequest::PROJECT) {
            return to_route('projects.index');
        }

        return back();
    }

    private function requestFor(string $publicId, User $user): DeletionRequest
    {
        return DeletionRequest::query()
            ->where('public_id', $publicId)
            ->whereIn('team_id', $user->teamIdsQuery())
            ->firstOrFail();
    }
}
