<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\GitSource;
use App\Data\Specs\ServiceSpec;
use App\Enums\AuditAction;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Enums\Dockerfiles\DockerfileGenerationTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Builds\BuildTasks;
use App\Services\Dockerfiles\DockerfileGenerationGate;
use App\Services\Git\GitHeads;
use Illuminate\Support\Facades\DB;

/**
 * "Generate a Dockerfile" (docs/20 §5): checks the opt-in, resolves the
 * branch head and starts the first step, a `plan` task with `inspect` that
 * reads the repository on a build server. Everything after that is driven
 * by AdvanceDockerfileGeneration.
 */
final readonly class StartDockerfileGeneration
{
    public function __construct(
        private GitHeads $heads,
        private CurrentEnvironmentSpec $currentSpec,
        private BuildTasks $tasks,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(ServiceInstance $instance, User $user, DockerfileGenerationTrigger $trigger, AuditActor $actor): DockerfileGeneration
    {
        $instance->loadMissing(['service', 'environment.project.team']);
        $team = $instance->environment->project->team;

        $reason = DockerfileGenerationGate::unavailableReason($team, $instance);

        if ($reason !== null) {
            throw DomainRuleViolation::forbidden(match ($reason) {
                DockerfileGenerationGate::TEAM_OFF => 'Dockerfile generation is off for this team. An owner can turn it on in Settings → Dockerfile generation.',
                DockerfileGenerationGate::UNSUPPORTED => 'launchroom can only generate Dockerfiles for app services built from a git repository.',
                default => 'Dockerfile generation is not set up on this launchroom. An instance owner can turn it on in Settings → Dockerfile generation.',
            });
        }

        $recent = DockerfileGeneration::query()
            ->where('team_id', $team->id)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($recent >= config()->integer('launchroom.dockerfile_generation.starts_per_team_per_hour')) {
            throw DomainRuleViolation::on('generation', 'This team started many Dockerfile generations in the last hour. Try again a little later.');
        }

        /** @var GitSource $source */
        $source = $instance->service->source;
        $branch = $this->heads->branchFor($instance, $source);

        try {
            $sha = $this->heads->head($instance->team_id, $source, $branch);
        } catch (GitHeadUnavailable $e) {
            throw DomainRuleViolation::on('generation', $e->getMessage());
        }

        $environment = $this->currentSpec->get($instance->environment);
        $service = $environment->service($instance->service->public_id);

        if (! $service instanceof ServiceSpec) {
            throw DomainRuleViolation::on('generation', 'This service is not part of the environment anymore.');
        }

        $settings = AiProviderSettings::current();

        return DB::transaction(function () use ($instance, $user, $trigger, $actor, $settings, $sha, $branch, $environment, $service, $source): DockerfileGeneration {
            ServiceInstance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();

            $running = DockerfileGeneration::query()
                ->where('service_instance_id', $instance->id)
                ->whereIn('status', DockerfileGenerationStatus::runningValues())
                ->exists();

            if ($running) {
                throw DomainRuleViolation::conflict('launchroom is already generating a Dockerfile for this service.');
            }

            $generation = DockerfileGeneration::query()->create([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'service_id' => $instance->service_id,
                'user_id' => $user->id,
                'trigger' => $trigger,
                'provider' => $settings->provider->value,
                'model' => $settings->effectiveModel(),
                'commit_sha' => $sha,
                'branch' => $branch,
                'max_attempts' => 1 + max(0, config()->integer('launchroom.dockerfile_generation.repairs')),
            ]);

            $task = $this->tasks->inspectPlan($instance, $environment, $service, $source, $sha);
            $generation->forceFill(['plan_task_id' => $task->id, 'step_started_at' => now()])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DockerfileGenerationStarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service_instance',
                subjectId: $instance->public_id,
                meta: ['generation' => $generation->public_id, 'model' => $generation->model, 'trigger' => $trigger->value, 'commit' => $sha],
            ));

            return $generation;
        });
    }
}
