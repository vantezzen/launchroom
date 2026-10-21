<?php

declare(strict_types=1);

namespace App\Models\Sessions;

use App\Enums\DatabaseEngine;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Enums\Sessions\SessionEndReason;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Console or Data session (docs/03 §11, docs/07). The ticket is only a
 * hash; the session key is encrypted and wiped once the server leased the
 * session task (it is only needed to seal it to that server).
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property int $environment_id
 * @property int $service_instance_id
 * @property int|null $server_id
 * @property int $user_id
 * @property InteractiveSessionKind $kind
 * @property InteractiveSessionStatus $status
 * @property string $workload_id
 * @property int $slot
 * @property string|null $shell
 * @property DatabaseEngine|null $engine
 * @property string $ticket_hash
 * @property Carbon $ticket_expires_at
 * @property Carbon|null $ticket_used_at
 * @property string|null $session_key base64url, 32 bytes
 * @property int|null $daemon_task_id
 * @property bool $record
 * @property int $idle_seconds
 * @property int $max_seconds
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property SessionEndReason|null $end_reason
 * @property int|null $exit_code
 * @property int $bytes_in
 * @property int $bytes_out
 * @property int|null $ended_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Environment $environment
 * @property-read ServiceInstance $serviceInstance
 * @property-read Server|null $server
 * @property-read User $user
 * @property-read DaemonTask|null $daemonTask
 */
#[Fillable(['team_id', 'project_id', 'environment_id', 'service_instance_id', 'server_id', 'user_id', 'kind', 'workload_id', 'slot', 'shell', 'engine', 'ticket_hash', 'ticket_expires_at', 'session_key', 'record', 'idle_seconds', 'max_seconds'])]
#[Hidden(['ticket_hash', 'session_key'])]
final class InteractiveSession extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    public static function publicIdPrefix(): string
    {
        return 'ses';
    }

    /**
     * The Pusher channel both ends join ("private-" + this on the wire).
     */
    public function channelName(): string
    {
        return 'session.'.$this->public_id;
    }

    /**
     * The only way a session's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(InteractiveSessionStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
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
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DaemonTask, $this>
     */
    public function daemonTask(): BelongsTo
    {
        return $this->belongsTo(DaemonTask::class);
    }

    /**
     * @return HasMany<DatabaseQuery, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(DatabaseQuery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InteractiveSessionKind::class,
            'status' => InteractiveSessionStatus::class,
            'engine' => DatabaseEngine::class,
            'end_reason' => SessionEndReason::class,
            'session_key' => 'encrypted',
            'record' => 'boolean',
            'slot' => 'integer',
            'idle_seconds' => 'integer',
            'max_seconds' => 'integer',
            'exit_code' => 'integer',
            'bytes_in' => 'integer',
            'bytes_out' => 'integer',
            'ticket_expires_at' => 'datetime',
            'ticket_used_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
