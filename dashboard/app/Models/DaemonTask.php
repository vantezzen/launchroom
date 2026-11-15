<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Protocol\V1\TaskResult;
use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Data\Tasks\TaskSeal;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\DaemonTaskPurpose;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Events\DaemonTaskFinished;
use App\Events\DaemonTaskQueued;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use stdClass;

/**
 * Imperative work leased by daemons (docs/03 §7, docs/07), created only
 * through App\Services\Tasks\DaemonTasks. `spec` is the stored wire
 * template; {@see self::taskSpec()} reads it typed.
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
 * @property TaskResult|null $result
 * @property string|null $error
 * @property string|null $progress
 * @property int|null $deployment_id
 * @property DaemonTaskPurpose|null $purpose
 * @property TaskSeal|null $seal
 * @property list<string> $required_features
 * @property string|null $required_platform
 * @property bool $platform_emulation
 * @property int|null $requested_by_id
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server|null $server
 */
#[Fillable(['team_id', 'server_id', 'pinned_to_server', 'required_role', 'type', 'spec', 'max_attempts', 'timeout_seconds', 'deployment_id', 'purpose', 'seal', 'required_features', 'required_platform', 'platform_emulation', 'requested_by_id'])]
final class DaemonTask extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @use HasStatusTransitions<DaemonTaskStatus> */
    use HasStatusTransitions;

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
     * Tasks still waiting for or held by a server (Pending, Leased).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', DaemonTaskStatus::nonTerminal());
    }

    /**
     * @param  DaemonTaskStatus  $from
     * @param  DaemonTaskStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
        }
    }

    /**
     * @param  DaemonTaskStatus  $from
     * @param  DaemonTaskStatus  $to
     */
    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void
    {
        if ($to->isTerminal()) {
            event(new DaemonTaskFinished($this->id));
        }

        if ($to === DaemonTaskStatus::Pending) {
            event(new DaemonTaskQueued($this));
        }
    }

    /**
     * @throws InvalidArgumentException when the stored spec doesn't decode
     */
    public function taskSpec(): TaskSpec
    {
        return TaskSpec::fromStored($this->type, $this->spec);
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
            'result' => TaskResult::class,
            'seal' => TaskSeal::class,
            'purpose' => DaemonTaskPurpose::class,
            'required_features' => 'array',
            'platform_emulation' => 'boolean',
        ];
    }
}
