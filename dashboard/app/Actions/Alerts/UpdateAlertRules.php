<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Alerts\AlertRulesInput;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\Alerts\AlertStatus;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertRuleSetting;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Saves "What we tell you about" (docs/15 §3.4): on/off, thresholds and
 * durations within each rule's bounds. Switching a rule off resolves its
 * open alerts quietly (nobody asked to hear about them any more).
 */
final readonly class UpdateAlertRules
{
    public function __construct(
        private ResolveAlert $resolve,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, AlertRulesInput $input, AuditActor $actor): void
    {
        foreach ($input->rules as $i => $item) {
            $bounds = $item->rule->threshold();
            $duration = $item->rule->duration();

            if ($bounds !== null && $item->threshold !== null && ($item->threshold < $bounds['min'] || $item->threshold > $bounds['max'])) {
                throw DomainRuleViolation::on("rules.{$i}.threshold", 'Choose a value between '.(int) $bounds['min'].' and '.(int) $bounds['max'].'.');
            }

            if ($duration !== null && $item->duration !== null && ($item->duration < $duration['min'] || $item->duration > $duration['max'])) {
                throw DomainRuleViolation::on("rules.{$i}.duration", "Choose between {$duration['min']} and {$duration['max']} minutes.");
            }
        }

        DB::transaction(function () use ($team, $input, $actor): void {
            $changed = [];

            foreach ($input->rules as $item) {
                $setting = AlertRuleSetting::query()->firstOrNew(['team_id' => $team->id, 'rule' => $item->rule->value]);
                $setting->enabled = $item->enabled;
                $setting->threshold = $item->rule->threshold() === null ? null : ($item->threshold ?? $setting->threshold);
                $setting->duration_minutes = $item->rule->duration() === null ? null : ($item->duration ?? $setting->duration_minutes);

                if ($setting->isDirty()) {
                    $changed[] = $item->rule->value;
                }

                $setting->save();

                if (! $item->enabled) {
                    $subjects = Alert::query()->where('team_id', $team->id)->where('rule', $item->rule->value)->where('status', AlertStatus::Firing->value)->pluck('subject_key');

                    foreach ($subjects as $subject) {
                        $this->resolve->handle($team, $item->rule, is_string($subject) ? $subject : '', 'This alert was switched off.', notify: false);
                    }
                }
            }

            if ($changed !== []) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::AlertRulesUpdated,
                    actor: $actor,
                    teamId: $team->id,
                    subjectType: 'team',
                    subjectId: $team->public_id,
                    meta: ['rules' => $changed],
                ));
            }
        });
    }
}
