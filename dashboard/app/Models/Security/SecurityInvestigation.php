<?php

declare(strict_types=1);

namespace App\Models\Security;

use App\Enums\Security\InvestigationStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An investigation of one service instance (docs/22 §7), optionally for one
 * finding. `inspections` holds the ContainerInspectionResults encrypted
 * under the project DEK (AD `investigation|<team>|<project>|<ivg_ id>`,
 * process commands can contain secrets); `report` is the generated
 * InvestigationReportData (sentences and steps, no commands). Kept 90 days.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $project_id
 * @property int $service_instance_id
 * @property int|null $finding_id
 * @property InvestigationStatus $status
 * @property int|null $started_by
 * @property bool $automatic
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property list<array{workload: string, server: string, serverName: string, slot: int, task: string|null, state: string, message?: string|null}> $replicas
 * @property string|null $inspections
 * @property array<string, mixed>|null $report
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read ServiceInstance $serviceInstance
 * @property-read SecurityFinding|null $finding
 * @property-read User|null $starter
 */
#[Fillable(['team_id', 'project_id', 'service_instance_id', 'finding_id', 'started_by', 'automatic', 'started_at', 'replicas'])]
#[Hidden(['inspections'])]
#[Table(name: 'security_investigations')]
final class SecurityInvestigation extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
        'replicas' => '[]',
        'automatic' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'ivg';
    }

    /**
     * The only way an investigation's status changes.
     */
    public function transitionTo(InvestigationStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
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
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<SecurityFinding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(SecurityFinding::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvestigationStatus::class,
            'automatic' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'replicas' => 'array',
            'report' => 'array',
        ];
    }
}
