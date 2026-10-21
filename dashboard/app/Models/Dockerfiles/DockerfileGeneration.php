<?php

declare(strict_types=1);

namespace App\Models\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileConfidence;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Enums\Dockerfiles\DockerfileGenerationTrigger;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One "Generate a Dockerfile" run (docs/20 §5).
 *
 * `context` is the scrubbed repository inspection sent to the model
 * (encrypted at rest); `attempts` the history of drafts with their lint,
 * build and start outcomes (masked log tails); `dockerfile`/`port`/… the
 * newest draft, immutable once `verified`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $service_instance_id
 * @property int $service_id
 * @property int|null $user_id
 * @property DockerfileGenerationTrigger $trigger
 * @property DockerfileGenerationStatus $status
 * @property string $provider
 * @property string $model
 * @property string|null $commit_sha
 * @property string|null $branch
 * @property int $attempt
 * @property int $max_attempts
 * @property int|null $plan_task_id
 * @property int|null $build_task_id
 * @property int|null $run_task_id
 * @property array{tree: list<string>, treeTruncated: bool, files: list<array{path: string, content: string, truncated: bool}>}|null $context
 * @property list<array{path: string, bytes: int, truncated: bool}>|null $files_sent
 * @property array<string, mixed>|null $detected plan summary + plan error the model sees
 * @property list<array<string, mixed>>|null $attempts
 * @property string|null $dockerfile
 * @property int|null $port
 * @property string|null $start_command
 * @property string|null $explanation
 * @property DockerfileConfidence|null $confidence
 * @property string|null $image
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $error
 * @property Carbon|null $step_started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property string|null $pull_request_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 * @property-read Service $service
 * @property-read User|null $user
 */
#[Fillable(['team_id', 'service_instance_id', 'service_id', 'user_id', 'trigger', 'provider', 'model', 'commit_sha', 'branch', 'max_attempts'])]
#[Hidden(['context'])]
final class DockerfileGeneration extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'reading',
        'attempt' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'dfg';
    }

    /**
     * The only way the status changes (docs/10 §2.2 rule 3). Open sheets
     * poll the generation, so nothing is broadcast.
     */
    public function transitionTo(DockerfileGenerationStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->forceFill(['step_started_at' => now()]);

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
        }

        $this->save();
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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
    public function planTask(): BelongsTo
    {
        return $this->belongsTo(DaemonTask::class, 'plan_task_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => DockerfileGenerationTrigger::class,
            'status' => DockerfileGenerationStatus::class,
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'context' => 'encrypted:array',
            'files_sent' => 'array',
            'detected' => 'array',
            'attempts' => 'array',
            'port' => 'integer',
            'confidence' => DockerfileConfidence::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'step_started_at' => 'datetime',
            'finished_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
