<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Alerts\AlertRule;
use App\Models\Alerts\AlertRuleSetting;

/**
 * Built-in rules merged with a team's `alert_rule_settings` rows. Values
 * outside a rule's bounds (an older row after the bounds changed) are
 * clamped instead of trusted.
 */
final readonly class AlertRuleSettings
{
    public function rule(int $teamId, AlertRule $rule): ResolvedRule
    {
        return $this->forTeams([$teamId])[$teamId][$rule->value];
    }

    /**
     * @return array<string, ResolvedRule> keyed by rule value
     */
    public function forTeam(int $teamId): array
    {
        return $this->forTeams([$teamId])[$teamId];
    }

    /**
     * @param  list<int>  $teamIds
     * @return array<int, array<string, ResolvedRule>>
     */
    public function forTeams(array $teamIds): array
    {
        $out = [];

        foreach ($teamIds as $teamId) {
            foreach (AlertRule::cases() as $rule) {
                $out[$teamId][$rule->value] = ResolvedRule::defaults($rule);
            }
        }

        if ($teamIds === []) {
            return $out;
        }

        $rows = AlertRuleSetting::query()->whereIn('team_id', $teamIds)->get();

        foreach ($rows as $row) {
            $rule = $row->rule;
            $bounds = $rule->threshold();
            $duration = $rule->duration();
            $out[$row->team_id][$rule->value] = new ResolvedRule(
                $rule,
                $row->enabled,
                $bounds === null ? null : min(max($row->threshold ?? $bounds['default'], $bounds['min']), $bounds['max']),
                $duration === null ? null : min(max($row->duration_minutes ?? $duration['default'], $duration['min']), $duration['max']),
            );
        }

        return $out;
    }
}
