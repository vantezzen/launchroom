<?php

declare(strict_types=1);

namespace App\Models\Alerts;

use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One open (or past) problem for one subject (docs/15 §3.1). At most one
 * firing row per (team, rule, subject_key): a partial unique index.
 * Raised and resolved only through App\Actions\Alerts\RaiseAlert and
 * ResolveAlert; the status changes only through transitionTo().
 *
 * @property int $id
 * @property string $public_id
 * @property AlertRule $rule
 * @property string $subject_key
 * @property AlertSeverity $severity
 * @property AlertStatus $status
 * @property string $title
 * @property string $summary
 * @property string|null $href
 * @property int|null $project_id
 * @property int|null $environment_id
 * @property int|null $service_instance_id
 * @property int|null $server_id
 * @property array<string, string> $facts
 * @property string|null $occurrence
 * @property int $occurrences
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolve_reason
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project|null $project
 * @property-read Environment|null $environment
 * @property-read ServiceInstance|null $serviceInstance
 * @property-read Server|null $server
 */
#[Fillable(['team_id', 'rule', 'subject_key', 'severity', 'title', 'summary', 'href', 'project_id', 'environment_id', 'service_instance_id', 'server_id', 'facts', 'occurrence', 'occurrences', 'opened_at', 'last_seen_at', 'notified_at'])]
final class Alert extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'firing',
        'facts' => '{}',
        'occurrences' => 1,
    ];

    public static function publicIdPrefix(): string
    {
        return 'alr';
    }

    public function transitionTo(AlertStatus $to, ?string $reason = null): void
    {
        $this->status->assertCanTransitionTo($to);

        $this->status = $to;

        if ($to === AlertStatus::Resolved) {
            $this->resolved_at = now();
            $this->resolve_reason = $reason === null ? null : mb_substr($reason, 0, 300);
        } else {
            $this->resolved_at = null;
            $this->resolve_reason = null;
        }

        $this->save();
    }

    public function isFiring(): bool
    {
        return $this->status === AlertStatus::Firing;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<AlertDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => AlertRule::class,
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'facts' => 'array',
            'occurrences' => 'integer',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
