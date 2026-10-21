<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertStatus;
use App\Models\Alerts\Alert;
use App\Models\Team;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the firing alert of (team, rule, subject), if any (docs/15
 * §3.2–3.3), and tells the channels that heard about it. Alerts that were
 * never notified resolve silently; `$notify = false` resolves silently too
 * (a rule switched off). Returns the resolved alert, or null when nothing
 * was firing.
 */
final readonly class ResolveAlert
{
    public function __construct(
        private AlertNotifier $notifier,
    ) {}

    public function handle(Team|int $team, AlertRule $rule, string $subject, ?string $reason = null, bool $notify = true): ?Alert
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return DB::transaction(function () use ($teamId, $rule, $subject, $reason, $notify): ?Alert {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["alert|{$teamId}|{$rule->value}|{$subject}"]);

            $alert = Alert::query()
                ->where('team_id', $teamId)
                ->where('rule', $rule->value)
                ->where('subject_key', $subject)
                ->where('status', AlertStatus::Firing->value)
                ->lockForUpdate()
                ->first();

            if (! $alert instanceof Alert) {
                return null;
            }

            $alert->transitionTo(AlertStatus::Resolved, $reason);

            if ($notify && $alert->notified_at !== null) {
                $this->notifier->queue($alert, AlertDeliveryEvent::Resolved);
            }

            return $alert;
        });
    }
}
