<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertStatus;
use App\Models\Alerts\Alert;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Alerts\AlertNotifier;
use App\Services\Alerts\AlertRuleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Raises (or refreshes) an alert: the API every part of launchroom uses
 * (docs/15 §3.2–3.3). Idempotent: calling it every minute while a problem
 * lasts keeps one alert open and notifies once.
 *
 * - The team switched the rule off → null, nothing stored.
 * - Firing alert for (team, rule, subject) → refreshed (last seen,
 *   occurrences, text, facts, severity). It notifies again only on a new
 *   `occurrence` after the rule's repeat window, or when it escalates
 *   from warning to critical.
 * - Resolved within the cooldown (10 min) → reopened; it notifies only if
 *   its last notification is older than the cooldown (flap guard).
 * - Otherwise a new alert, notified to every channel that wants it.
 *
 * Serialised per key with an advisory lock; deliveries are queued after
 * commit.
 */
final readonly class RaiseAlert
{
    public function __construct(
        private AlertRuleSettings $rules,
        private AlertNotifier $notifier,
    ) {}

    public function handle(AlertSignal $signal): ?Alert
    {
        if (! $this->rules->rule($signal->team->id, $signal->rule)->enabled) {
            return null;
        }

        return DB::transaction(function () use ($signal): Alert {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["alert|{$signal->team->id}|{$signal->rule->value}|{$signal->subject}"]);

            $now = CarbonImmutable::now();
            $severity = $signal->severity ?? $signal->rule->defaultSeverity();
            $firing = $this->latest($signal, AlertStatus::Firing);

            if ($firing instanceof Alert) {
                $escalated = ! $firing->severity->atLeast($severity);
                $repeat = $signal->rule->repeatAfterMinutes();
                $newOccurrence = $signal->occurrence !== null && $signal->occurrence !== $firing->occurrence;
                $notify = $escalated || ($newOccurrence && $repeat !== null && ($firing->notified_at === null || $firing->notified_at->lessThanOrEqualTo($now->subMinutes($repeat))));

                $this->fill($firing, $signal, $now);
                $firing->occurrences++;
                $firing->save();

                if ($notify) {
                    $this->notify($firing, AlertDeliveryEvent::Repeated, $now);
                }

                return $firing;
            }

            $recent = $this->latest($signal, AlertStatus::Resolved);

            if ($recent instanceof Alert && $recent->resolved_at?->greaterThanOrEqualTo($now->subMinutes(AlertRule::COOLDOWN_MINUTES)) === true) {
                $this->fill($recent, $signal, $now);
                $recent->occurrences++;
                $recent->transitionTo(AlertStatus::Firing);

                if ($recent->notified_at === null || $recent->notified_at->lessThan($now->subMinutes(AlertRule::COOLDOWN_MINUTES))) {
                    $this->notify($recent, AlertDeliveryEvent::Fired, $now);
                }

                return $recent;
            }

            $alert = new Alert([
                'team_id' => $signal->team->id,
                'rule' => $signal->rule,
                'subject_key' => $signal->subject,
                'opened_at' => $now,
            ]);
            $this->fill($alert, $signal, $now);
            $alert->save();
            $this->notify($alert, AlertDeliveryEvent::Fired, $now);

            return $alert;
        });
    }

    private function latest(AlertSignal $signal, AlertStatus $status): ?Alert
    {
        return Alert::query()
            ->where('team_id', $signal->team->id)
            ->where('rule', $signal->rule->value)
            ->where('subject_key', $signal->subject)
            ->where('status', $status->value)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    private function fill(Alert $alert, AlertSignal $signal, CarbonImmutable $now): void
    {
        $signal->serviceInstance?->loadMissing('environment');

        $alert->fill([
            'severity' => $signal->severity ?? $signal->rule->defaultSeverity(),
            'title' => $signal->title,
            'summary' => $signal->summary,
            'href' => $signal->href ?? $this->defaultHref($signal),
            'project_id' => $signal->project->id ?? $signal->environment->project_id ?? $signal->serviceInstance?->environment->project_id,
            'environment_id' => $signal->environment->id ?? $signal->serviceInstance?->environment_id,
            'service_instance_id' => $signal->serviceInstance?->id,
            'server_id' => $signal->server?->id,
            'facts' => $signal->facts,
            'last_seen_at' => $now,
        ]);

        if ($signal->occurrence !== null) {
            $alert->occurrence = $signal->occurrence;
        }
    }

    private function notify(Alert $alert, AlertDeliveryEvent $event, CarbonImmutable $now): void
    {
        if ($this->notifier->queue($alert, $event) > 0) {
            $alert->notified_at = $now;
            $alert->save();
        }
    }

    /**
     * Where to look when the caller gave no link: the service's panel, the
     * server page, the project's canvas.
     */
    private function defaultHref(AlertSignal $signal): string
    {
        $instance = $signal->serviceInstance;

        if ($instance instanceof ServiceInstance) {
            $instance->loadMissing(['service', 'environment.project']);

            return "/p/{$instance->environment->project->public_id}/{$instance->environment->public_id}/s/{$instance->service->public_id}/deployments";
        }

        if ($signal->server instanceof Server) {
            return "/servers/{$signal->server->public_id}";
        }

        if ($signal->environment instanceof Environment) {
            $signal->environment->loadMissing('project');

            return "/p/{$signal->environment->project->public_id}/{$signal->environment->public_id}";
        }

        return $signal->project instanceof Project ? "/p/{$signal->project->public_id}" : '/activity';
    }
}
