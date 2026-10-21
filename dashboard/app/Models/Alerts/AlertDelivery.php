<?php

declare(strict_types=1);

namespace App\Models\Alerts;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Enums\Alerts\AlertDeliveryStatus;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one channel (docs/15 §3.1). `public_id` is the webhook's
 * `X-Launchroom-Delivery` (stable across retries, so receivers can dedup).
 * `alert_id` is null for test messages.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $alert_id
 * @property int $channel_id
 * @property AlertDeliveryEvent $event
 * @property AlertDeliveryStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Alert|null $alert
 * @property-read AlertChannel $channel
 */
#[Fillable(['alert_id', 'channel_id', 'event'])]
final class AlertDelivery extends Model
{
    use HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'adl';
    }

    public function transitionTo(AlertDeliveryStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);

        $this->status = $to;
        $this->error = $error === null ? null : mb_substr($error, 0, 500);

        if ($to === AlertDeliveryStatus::Sent) {
            $this->sent_at = now();
        }

        $this->save();
    }

    /**
     * @return BelongsTo<Alert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * @return BelongsTo<AlertChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertChannel::class, 'channel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AlertDeliveryEvent::class,
            'status' => AlertDeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
