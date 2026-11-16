<?php

declare(strict_types=1);

namespace App\Http\Controllers\Alerts;

use App\Data\Alerts\AlertChannelData;
use App\Data\Alerts\AlertRuleData;
use App\Data\Alerts\AlertSummaryData;
use App\Data\Alerts\ResolvedRule;
use App\Enums\Alerts\AlertStatus;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\AlertRuleSettings;
use App\Services\Alerts\Senders\EmailSender;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Notifications (docs/15 §3.6): where the team hears about
 * alerts and what it hears about, each rule with its latest alert. Open
 * alerts live in Activity; the page shows how many are open and the two
 * most severe.
 */
final class NotificationSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user, AlertRuleSettings $rules, #[CurrentTeam] Team $team): Response
    {
        Gate::authorize('viewAny', [AlertChannel::class, $team]);

        $channels = AlertChannel::query()->where('team_id', $team->id)->orderBy('id')->get();
        $firing = Alert::query()->where('team_id', $team->id)->where('status', AlertStatus::Firing->value);
        $openCount = (clone $firing)->count();
        $open = (clone $firing)->latest('opened_at')->limit(50)->get()
            ->sortByDesc(static fn (Alert $alert): int => $alert->severity->rank())
            ->take(2);
        $lastIds = Alert::query()->where('team_id', $team->id)->groupBy('rule')->selectRaw('max(id) as id')->pluck('id');
        /** @var array<string, Alert> $last */
        $last = Alert::query()->whereIn('id', $lastIds)->get()->keyBy(static fn (Alert $alert): string => $alert->rule->value)->all();

        return Inertia::render('settings/notifications', [
            'channels' => array_values($channels->map(static fn (AlertChannel $channel): AlertChannelData => AlertChannelData::fromChannel($channel, Gate::allows('update', $channel)))->all()),
            'rules' => array_values(array_map(
                static fn (ResolvedRule $rule): AlertRuleData => AlertRuleData::fromResolved($rule, $last[$rule->rule->value] ?? null),
                $rules->forTeam($team->id),
            )),
            'openAlerts' => array_values($open->map(AlertSummaryData::fromAlert(...))->all()),
            'openAlertCount' => $openCount,
            'canManage' => Gate::allows('create', [AlertChannel::class, $team]),
            'mailConfigured' => EmailSender::configured(),
            'defaultEmail' => $user->email,
        ]);
    }
}
