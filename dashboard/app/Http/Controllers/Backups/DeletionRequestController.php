<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Actions\Backups\RetryFinalBackup;
use App\Actions\Backups\SkipFinalBackup;
use App\Enums\Backups\DeletionOutcome;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Backups\DeletionRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Retry a blocked final backup, or delete without one (docs/14 §9.13).
 */
final class DeletionRequestController extends Controller
{
    public function retry(Request $request, DeletionRequest $deletion, #[CurrentUser] User $user, RetryFinalBackup $retry): RedirectResponse
    {
        Gate::authorize('retry', $deletion);

        $retry->handle($deletion, $user, $this->auditActor($request, $user));
        Toast::info('Taking the final backup again.');

        return back();
    }

    public function skip(Request $request, DeletionRequest $deletion, #[CurrentUser] User $user, SkipFinalBackup $skip): RedirectResponse
    {
        Gate::authorize('skipFinalBackup', $deletion);

        $outcome = $skip->handle($deletion, $user, $this->auditActor($request, $user));
        Toast::success(match (true) {
            $outcome === DeletionOutcome::Deleted => "{$deletion->subject_name} deleted without a final backup.",
            $deletion->subject_type === DeletionRequest::SERVICE_INSTANCE => 'The removal no longer waits for a final backup. Deploy to apply it.',
            default => 'The deletion no longer waits for a final backup.',
        });

        if ($outcome === DeletionOutcome::Deleted && $deletion->subject_type === DeletionRequest::PROJECT) {
            return to_route('projects.index');
        }

        return back();
    }
}
