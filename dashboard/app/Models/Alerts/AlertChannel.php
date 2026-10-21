<?php

declare(strict_types=1);

namespace App\Models\Alerts;

use App\Enums\Alerts\AlertChannelKind;
use App\Enums\Alerts\AlertSeverity;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Services\Alerts\AlertChannelSecrets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a team hears about alerts (docs/15 §3.5, docs/07 `alert_channels`).
 * Slack/Discord/webhook URLs and the webhook signing secret are envelope
 * encrypted in `secret` and only ever opened by {@see AlertChannelSecrets};
 * `target_hint` is the one clear-text trace of them (host + last 4).
 *
 * @property int $id
 * @property string $public_id
 * @property AlertChannelKind $kind
 * @property string $name
 * @property list<string>|null $recipients
 * @property string|null $target_hint
 * @property string|null $secret
 * @property string|null $wrapped_dek
 * @property string|null $kek_id
 * @property bool $enabled
 * @property AlertSeverity $min_severity
 * @property CarbonImmutable|null $last_delivery_at
 * @property bool|null $last_delivery_ok
 * @property string|null $last_error
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'kind', 'name', 'recipients', 'target_hint', 'enabled', 'min_severity', 'created_by'])]
#[Hidden(['secret', 'wrapped_dek', 'kek_id'])]
final class AlertChannel extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'min_severity' => 'warning',
    ];

    public static function publicIdPrefix(): string
    {
        return 'ach';
    }

    /**
     * Whether an alert of this severity reaches the channel.
     */
    public function wants(AlertSeverity $severity): bool
    {
        return $this->enabled && $severity->atLeast($this->min_severity);
    }

    /**
     * Records the outcome of the last message (Settings shows it).
     */
    public function recordDelivery(bool $ok, ?string $error = null): void
    {
        $this->forceFill([
            'last_delivery_at' => now(),
            'last_delivery_ok' => $ok,
            'last_error' => $ok || $error === null ? null : mb_substr($error, 0, 500),
        ])->save();
    }

    /**
     * @return HasMany<AlertDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class, 'channel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AlertChannelKind::class,
            'recipients' => 'array',
            'enabled' => 'boolean',
            'min_severity' => AlertSeverity::class,
            'last_delivery_at' => 'datetime',
            'last_delivery_ok' => 'boolean',
        ];
    }
}
