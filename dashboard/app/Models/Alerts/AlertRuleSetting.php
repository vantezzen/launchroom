<?php

declare(strict_types=1);

namespace App\Models\Alerts;

use App\Enums\Alerts\AlertRule;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A team's override of a built-in rule (docs/15 §3.4). No row = defaults.
 *
 * @property int $id
 * @property AlertRule $rule
 * @property bool $enabled
 * @property float|null $threshold
 * @property int|null $duration_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'rule', 'enabled', 'threshold', 'duration_minutes'])]
final class AlertRuleSetting extends Model
{
    use BelongsToTeam;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => AlertRule::class,
            'enabled' => 'boolean',
            'threshold' => 'float',
            'duration_minutes' => 'integer',
        ];
    }
}
