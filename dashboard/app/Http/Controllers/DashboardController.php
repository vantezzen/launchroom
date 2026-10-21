<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Instance\OnboardingProgressData;
use App\Models\User;
use App\Queries\OnboardingChecklist;
use App\Services\Instance\RecoveryMode;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * Where "/" lands (docs/08 §2): instance owners see the recovery checklist
 * while recovery mode is on (docs/14 §5.2), else the first-run checklist
 * until it is complete or dismissed; everyone else, and owners afterwards,
 * the team's projects. Acts on the signed-in user only.
 */
final class DashboardController extends Controller
{
    public function __invoke(#[CurrentUser] User $user, OnboardingChecklist $checklist): RedirectResponse
    {
        // After `install.sh --restore` the dashboard opens in recovery mode (docs/14 §5.2).
        if ($user->is_instance_owner && RecoveryMode::active()) {
            return to_route('recovery.show');
        }

        $progress = $checklist->progressFor($user);

        if ($progress instanceof OnboardingProgressData && ! $progress->complete && ! $progress->dismissed) {
            return to_route('onboarding.show');
        }

        return to_route('projects.index');
    }
}
