<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alerts;

use App\Data\Alerts\AlertChannelData;
use App\Data\Alerts\AlertRuleData;
use App\Data\Alerts\AlertSummaryData;
use App\Enums\Alerts\AlertStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertChannel;
use App\Models\User;
use App\Services\Alerts\AlertRuleSettings;
use App\Services\Alerts\Senders\EmailSender;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Notifications (docs/15 §3.6): where the team hears about
 * alerts, what it hears about, and what is open right now.
 */
final class NotificationSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user, AlertRuleSettings $rules): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [AlertChannel::class, $team]);

        $channels = AlertChannel::query()->where('team_id', $team->id)->orderBy('id')->get();
        $open = Alert::query()->where('team_id', $team->id)->where('status', AlertStatus::Firing->value)->latest('opened_at')->limit(10)->get();

        return Inertia::render('settings/notifications', [
            'channels' => array_values($channels->map(static fn (AlertChannel $channel): AlertChannelData => AlertChannelData::fromChannel($channel, Gate::allows('update', $channel)))->all()),
            'rules' => array_values(array_map(AlertRuleData::fromResolved(...), $rules->forTeam($team->id))),
            'openAlerts' => array_values($open->map(AlertSummaryData::fromAlert(...))->all()),
            'canManage' => Gate::allows('create', [AlertChannel::class, $team]),
            'mailConfigured' => EmailSender::configured(),
            'defaultEmail' => $user->email,
        ]);
    }
}
