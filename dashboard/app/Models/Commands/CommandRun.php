<?php

declare(strict_types=1);

namespace App\Models\Commands;

use App\Broadcasting\ServiceActivityTopic;
use App\Enums\Commands\CommandRunStatus;
use App\Enums\Contracts\HasTransitionTable;
use App\Events\Broadcasts\ServiceActivityChanged;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One one-off command (D105, docs/03 §7.5): an argv run once in the live
 * deployment's image of a service, as a `run_once` task. Its output is the
 * task's log chunks. The row never holds an env value.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property int $environment_id
 * @property int $service_instance_id
 * @property int|null $deployment_id the deployment whose image ran
 * @property int|null $server_id
 * @property int|null $user_id who ran it (null once the account is gone)
 * @property string|null $api_token_id the token's public id when it came through the API
 * @property int|null $daemon_task_id
 * @property list<string> $argv
 * @property string|null $command_name the catalogue or launchroom.json command it came from
 * @property CommandRunStatus $status
 * @property int|null $exit_code
 * @property string|null $error why it didn't run to its end
 * @property int $timeout_seconds
 * @property bool $output_truncated
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $cancel_requested_at
 * @property int|null $cancelled_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Environment $environment
 * @property-read ServiceInstance $serviceInstance
 * @property-read Deployment|null $deployment
 * @property-read Server|null $server
 * @property-read User|null $user
 * @property-read User|null $cancelledBy
 * @property-read DaemonTask|null $daemonTask
 */
#[Fillable(['team_id', 'project_id', 'environment_id', 'service_instance_id', 'deployment_id', 'server_id', 'user_id', 'api_token_id', 'argv', 'command_name', 'timeout_seconds'])]
#[Table(name: 'command_runs')]
final class CommandRun extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @use HasStatusTransitions<CommandRunStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'output_truncated' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'run';
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
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
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
            'status' => CommandRunStatus::class,
            'argv' => 'array',
            'exit_code' => 'integer',
            'timeout_seconds' => 'integer',
            'output_truncated' => 'boolean',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to === CommandRunStatus::Running) {
            $this->started_at ??= now();
        }

        if ($to->isTerminal()) {
            $this->finished_at = now();
            $this->error = $note ?? $this->error;
        }
    }

    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void
    {
        ServiceActivityChanged::about($this->serviceInstance, ServiceActivityTopic::Commands);
    }
}
