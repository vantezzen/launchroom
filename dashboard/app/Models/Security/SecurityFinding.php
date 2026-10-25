<?php

declare(strict_types=1);

namespace App\Models\Security;

use App\Enums\Security\DismissReason;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\Security\Severity;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One (service instance, advisory group, component version) whose
 * advisories match the instance's live image (docs/22 §5.5). The level and
 * reasons are recomputed by EvaluateSecurityFindings; the status changes
 * only through transitionTo().
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $project_id
 * @property int $environment_id
 * @property int $service_instance_id
 * @property int|null $deployment_id
 * @property int|null $inventory_id
 * @property string $group_key
 * @property list<string> $advisory_ids
 * @property string $ecosystem
 * @property string $name
 * @property string $version
 * @property string $kind
 * @property string $scope
 * @property string $presence
 * @property bool $direct
 * @property FindingLevel $level
 * @property list<string> $reasons
 * @property list<array{rule: string, passed: bool, label: string}> $checks
 * @property string|null $attack_vector
 * @property Severity $severity
 * @property string|null $cvss_score
 * @property string|null $fixed_version
 * @property bool $known_exploited
 * @property Carbon|null $known_exploited_since
 * @property bool $public
 * @property SecurityFindingStatus $status
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $level_changed_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $dismissed_at
 * @property int|null $dismissed_by
 * @property DismissReason|null $dismiss_reason
 * @property string|null $dismiss_note
 * @property Carbon|null $notified_at
 * @property int|null $investigation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Environment $environment
 * @property-read ServiceInstance $serviceInstance
 * @property-read Deployment|null $deployment
 * @property-read ImageInventory|null $inventory
 * @property-read SecurityInvestigation|null $investigation
 * @property-read User|null $dismisser
 */
#[Fillable(['team_id', 'project_id', 'environment_id', 'service_instance_id', 'deployment_id', 'inventory_id', 'group_key', 'advisory_ids', 'ecosystem', 'name', 'version', 'kind', 'scope', 'presence', 'direct', 'level', 'reasons', 'checks', 'attack_vector', 'severity', 'cvss_score', 'fixed_version', 'known_exploited', 'known_exploited_since', 'public', 'first_seen_at', 'last_seen_at', 'level_changed_at'])]
#[Table(name: 'security_findings')]
final class SecurityFinding extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'advisory_ids' => '[]',
        'reasons' => '[]',
        'checks' => '[]',
        'severity' => 'unknown',
        'known_exploited' => false,
        'public' => false,
        'direct' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'fnd';
    }

    /**
     * The only way a finding's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(SecurityFindingStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === SecurityFindingStatus::Resolved) {
            $this->forceFill(['resolved_at' => now()]);
        }

        if ($to === SecurityFindingStatus::Open) {
            $this->forceFill(['resolved_at' => null, 'dismissed_at' => null, 'dismissed_by' => null, 'dismiss_reason' => null, 'dismiss_note' => null]);
        }

        $this->save();
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
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return BelongsTo<ImageInventory, $this>
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ImageInventory::class);
    }

    /**
     * @return BelongsTo<SecurityInvestigation, $this>
     */
    public function investigation(): BelongsTo
    {
        return $this->belongsTo(SecurityInvestigation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'advisory_ids' => 'array',
            'direct' => 'boolean',
            'level' => FindingLevel::class,
            'reasons' => 'array',
            'checks' => 'array',
            'severity' => Severity::class,
            'cvss_score' => 'decimal:1',
            'known_exploited' => 'boolean',
            'known_exploited_since' => 'date',
            'public' => 'boolean',
            'status' => SecurityFindingStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'level_changed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'dismiss_reason' => DismissReason::class,
            'notified_at' => 'datetime',
        ];
    }

    /**
     * Keyset pagination: findings older than the cursor's row.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function before(Builder $query, int $id): void
    {
        $query->where($this->getKeyName(), '<', $id);
    }
}
