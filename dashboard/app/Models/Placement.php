<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProbeState;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One workload: a replica slot of a deployment on a server. Desired fields
 * come from the scheduler/orchestrator, observed ones from daemon reports
 * (docs/07 `placements`).
 *
 * @property int $id
 * @property int $service_instance_id
 * @property int $deployment_id
 * @property int $server_id
 * @property int $slot
 * @property string $workload_id
 * @property int $memory_mb
 * @property float $cpus CPU limit reserved (capacity accounting)
 * @property WorkloadRole $role
 * @property Carbon|null $drain_until
 * @property WorkloadPhase $phase
 * @property ProbeState|null $health
 * @property string|null $container_id
 * @property string|null $message
 * @property array{stage: string, downloaded: int, total: int}|null $pull in-flight image pull while `pulling` (docs/04 §11 "Pulls")
 * @property int $restart_count
 * @property Carbon|null $observed_at
 * @property int|null $mesh_port mesh-published port on its server's mesh IP (App\Services\NodeStates\MeshUpstreams)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Deployment $deployment
 * @property-read ServiceInstance $serviceInstance
 * @property-read Server $server
 */
#[Fillable(['team_id', 'service_instance_id', 'deployment_id', 'server_id', 'slot', 'workload_id', 'memory_mb', 'cpus', 'role', 'drain_until'])]
final class Placement extends Model
{
    use BelongsToTeam;

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'serving',
        'phase' => 'pending',
        'restart_count' => 0,
    ];

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'memory_mb' => 'integer',
            'cpus' => 'float',
            'role' => WorkloadRole::class,
            'drain_until' => 'datetime',
            'phase' => WorkloadPhase::class,
            'health' => ProbeState::class,
            'restart_count' => 'integer',
            'observed_at' => 'datetime',
            'pull' => 'array',
        ];
    }
}
