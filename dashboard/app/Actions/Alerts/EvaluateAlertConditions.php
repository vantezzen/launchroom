<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Enums\Alerts\AlertStatus;
use App\Models\Alerts\Alert;
use App\Models\Team;
use App\Services\Alerts\AlertRuleSettings;
use App\Services\Alerts\Conditions\AlertConditions;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;

/**
 * The per-minute evaluator of the condition rules (docs/15 §3.4,
 * `launchroom:alerts:evaluate`): for each condition it raises the failing
 * subjects of every team that has the rule on (RaiseAlert dedups) and
 * resolves the rule's firing alerts whose subject is no longer failing.
 * Idempotent.
 */
final readonly class EvaluateAlertConditions
{
    public function __construct(
        private AlertRuleSettings $settings,
        private AlertConditions $conditions,
        private RaiseAlert $raise,
        private ResolveAlert $resolve,
    ) {}

    /**
     * @return array{raised: int, resolved: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $teamIds = array_values(array_map(Cast::id(...), Team::query()->pluck('id')->all()));
        $rules = $this->settings->forTeams($teamIds);
        $counts = ['raised' => 0, 'resolved' => 0];

        foreach ($this->conditions->all as $condition) {
            $rule = $condition->rule();
            /** @var array<string, true> $firing "team|subject" */
            $firing = [];

            foreach (Alert::query()->where('rule', $rule->value)->where('status', AlertStatus::Firing->value)->get(['team_id', 'subject_key']) as $alert) {
                $firing[$alert->team_id.'|'.$alert->subject_key] = true;
            }

            $failing = $condition->failing($rules, $firing, $now);

            foreach ($failing as $key => $signal) {
                $teamId = (int) explode('|', $key, 2)[0];

                if (! ($rules[$teamId][$rule->value] ?? null)?->enabled) {
                    continue;
                }

                if ($this->raise->handle($signal()) instanceof Alert && ! isset($firing[$key])) {
                    $counts['raised']++;
                }
            }

            foreach (array_keys($firing) as $key) {
                if (isset($failing[$key])) {
                    continue;
                }

                [$teamId, $subject] = explode('|', $key, 2);

                if ($this->resolve->handle((int) $teamId, $rule, $subject, $condition->resolvedReason()) instanceof Alert) {
                    $counts['resolved']++;
                }
            }
        }

        return $counts;
    }
}
