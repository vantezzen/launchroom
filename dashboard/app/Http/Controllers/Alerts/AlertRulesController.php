<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alerts;

use App\Actions\Alerts\UpdateAlertRules;
use App\Data\Alerts\AlertRulesInput;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Alerts\AlertChannel;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "What we tell you about" (docs/15 §3.4).
 */
final class AlertRulesController extends Controller
{
    public function update(Request $request, AlertRulesInput $input, #[CurrentUser] User $user, UpdateAlertRules $update): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('manageRules', [AlertChannel::class, $team]);

        $update->handle($team, $input, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alert settings saved.']);

        return back();
    }
}
