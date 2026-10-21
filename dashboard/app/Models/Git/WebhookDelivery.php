<?php

declare(strict_types=1);

namespace App\Models\Git;

use App\Enums\Git\WebhookDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal metadata of a verified webhook delivery: replay protection
 * (unique `delivery_id`, docs/09 R17) and webhook health. Never the payload.
 *
 * @property int $id
 * @property string $delivery_id
 * @property int|null $github_app_id
 * @property int|null $git_connection_id
 * @property int|null $team_id
 * @property string $event
 * @property string|null $action
 * @property int|null $installation_id
 * @property int|null $repository_id
 * @property WebhookDeliveryStatus $status
 * @property string|null $error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property-read GitHubApp|null $app
 * @property-read GitConnection|null $gitConnection
 */
#[Fillable(['delivery_id', 'github_app_id', 'team_id', 'event', 'action', 'installation_id', 'repository_id', 'received_at'])]
#[WithoutTimestamps]
final class WebhookDelivery extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'received',
    ];

    /**
     * @return BelongsTo<GitHubApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(GitHubApp::class, 'github_app_id');
    }

    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }

    /**
     * The only way a delivery's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(WebhookDeliveryStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);

        $this->status = $to;
        $this->error = $error === null ? null : mb_substr($error, 0, 255);
        $this->processed_at = now();
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'repository_id' => 'integer',
            'status' => WebhookDeliveryStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
