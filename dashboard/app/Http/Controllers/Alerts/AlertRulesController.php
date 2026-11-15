<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alerts;

use App\Actions\Alerts\UpdateAlertRules;
use App\Data\Alerts\AlertRulesInput;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Alerts\AlertChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "What we tell you about" (docs/15 §3.4).
 */
final class AlertRulesController extends Controller
{
    public function update(Request $request, AlertRulesInput $input, #[CurrentUser] User $user, UpdateAlertRules $update, #[CurrentTeam] Team $team): RedirectResponse
    {
        Gate::authorize('manageRules', [AlertChannel::class, $team]);

        $update->handle($team, $input, $this->auditActor($request, $user));

        Toast::success('Alert settings saved.');

        return back();
    }
}
