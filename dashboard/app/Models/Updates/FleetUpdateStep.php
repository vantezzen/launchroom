<?php

declare(strict_types=1);

namespace App\Models\Updates;

use App\Enums\Contracts\HasTransitionTable;
use App\Enums\Updates\FleetUpdateStepStatus;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\DaemonTask;
use App\Models\Server;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One server's turn in a {@see FleetUpdate}.
 *
 * @property int $id
 * @property int $fleet_update_id
 * @property int|null $server_id
 * @property int $position
 * @property string $server_name
 * @property string|null $from_version
 * @property FleetUpdateStepStatus $status
 * @property int|null $daemon_task_id
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FleetUpdate $fleetUpdate
 * @property-read Server|null $server
 * @property-read DaemonTask|null $daemonTask
 */
#[Fillable(['fleet_update_id', 'server_id', 'position', 'server_name', 'from_version'])]
final class FleetUpdateStep extends Model
{
    /** @use HasStatusTransitions<FleetUpdateStepStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @param  FleetUpdateStepStatus  $from
     * @param  FleetUpdateStepStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to === FleetUpdateStepStatus::Updating) {
            $this->forceFill(['started_at' => now()]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
            $this->error = $note;
        }
    }

    /**
     * @return BelongsTo<FleetUpdate, $this>
     */
    public function fleetUpdate(): BelongsTo
    {
        return $this->belongsTo(FleetUpdate::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<DaemonTask, $this>
     */
    public function daemonTask(): BelongsTo
    {
        return $this->belongsTo(DaemonTask::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FleetUpdateStepStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
