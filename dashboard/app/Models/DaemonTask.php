<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Events\DaemonTaskFinished;
use App\Events\DaemonTaskQueued;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * Imperative work leased by daemons (docs/03 §7, docs/07).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $server_id
 * @property bool $pinned_to_server
 * @property ServerRole|null $required_role
 * @property DaemonTaskType $type
 * @property stdClass $spec
 * @property DaemonTaskStatus $status
 * @property int $attempt
 * @property int $max_attempts
 * @property Carbon|null $leased_at
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $deadline_at
 * @property int $timeout_seconds
 * @property Carbon|null $cancel_requested_at
 * @property int|null $completed_attempt
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property string|null $progress
 * @property int|null $deployment_id
 * @property string|null $purpose
 * @property array<string, mixed>|null $seal
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server|null $server
 */
#[Fillable(['team_id', 'server_id', 'pinned_to_server', 'required_role', 'type', 'spec', 'max_attempts', 'timeout_seconds', 'deployment_id', 'purpose', 'seal'])]
final class DaemonTask extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Realtime nudges for servers that may lease it (docs/03 §4).
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => DaemonTaskQueued::class,
    ];

    public static function publicIdPrefix(): string
    {
        return 'tsk';
    }

    /**
     * The only way a task's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(DaemonTaskStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
        }

        $this->save();

        if ($to->isTerminal()) {
            event(new DaemonTaskFinished($this->id));
        }

        if ($to === DaemonTaskStatus::Pending) {
            event(new DaemonTaskQueued($this));
        }
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
            'pinned_to_server' => 'boolean',
            'required_role' => ServerRole::class,
            'type' => DaemonTaskType::class,
            // Objects, not arrays: keeps empty maps as `{}` when re-encoded (protocol/README.md).
            'spec' => 'object',
            'status' => DaemonTaskStatus::class,
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'timeout_seconds' => 'integer',
            'completed_attempt' => 'integer',
            'leased_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'deadline_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'finished_at' => 'datetime',
            'result' => 'array',
            'seal' => 'array',
        ];
    }
}
