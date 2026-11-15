<?php

declare(strict_types=1);

namespace App\Actions\Dockerfiles;

use App\Contracts\DockerfileModel;
use App\Data\Dockerfiles\DockerfileAttemptRecordData;
use App\Data\Dockerfiles\DockerfileDetectedData;
use App\Data\Dockerfiles\RepositoryContextData;
use App\Data\Services\GitSource;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Exceptions\DockerfileModelFailed;
use App\Exceptions\DomainRuleViolation;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Builds\BuildTasks;
use App\Services\Dockerfiles\DockerfileAttempts;
use App\Services\Dockerfiles\DockerfilePinner;
use App\Services\Variables\SpecVariableResolver;
use App\Support\BuildKey;
use App\Support\Dockerfiles\DockerfileDraft;
use App\Support\Dockerfiles\DockerfileLinter;
use App\Support\Dockerfiles\DockerfilePrompt;
use App\Support\Dockerfiles\DockerfilePromptBuilder;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * One drafting attempt (docs/20 §4–5), run by DraftDockerfileJob: build the
 * prompt from the scrubbed context and earlier attempts, ask the model
 * (outside any transaction or lock), then lint, pin the base images and
 * start the verification build. A refused or unusable draft is a failed
 * attempt that goes back to the model while attempts remain.
 */
final readonly class DraftDockerfile
{
    public function __construct(
        private DockerfileModel $model,
        private DockerfilePinner $pinner,
        private DockerfileAttempts $attempts,
        private BuildTasks $tasks,
        private CurrentEnvironmentSpec $currentSpec,
        private SpecVariableResolver $variables,
    ) {}

    /**
     * @return bool whether another draft should be dispatched
     */
    public function handle(int $generationId): bool
    {
        $prepared = DB::transaction(function () use ($generationId): ?array {
            $generation = DockerfileGeneration::query()->with(['serviceInstance.environment', 'service'])->whereKey($generationId)->lockForUpdate()->first();

            if (! $generation instanceof DockerfileGeneration || $generation->status !== DockerfileGenerationStatus::Drafting || $generation->attempt >= $generation->max_attempts) {
                return null;
            }

            [$environment, $service] = $this->specs($generation);
            $source = $generation->service->source;
            $attempts = array_values($generation->attempts?->items() ?? []);
            $previous = array_values(array_filter($attempts, static fn (DockerfileAttemptRecordData $attempt): bool => $attempt->dockerfile !== null));

            $generation->attempt++;
            $generation->forceFill(['attempts' => [...$attempts, new DockerfileAttemptRecordData(n: $generation->attempt, startedAt: now()->toIso8601ZuluString())], 'step_started_at' => now()]);
            $generation->save();

            $prompt = new DockerfilePrompt(
                system: DockerfilePromptBuilder::SYSTEM,
                user: DockerfilePromptBuilder::user(
                    $source instanceof GitSource ? $source->rootDirectory : '',
                    $generation->context ?? new RepositoryContextData,
                    $generation->detected ?? new DockerfileDetectedData,
                    $service === null ? [] : BuildKey::buildScopedKeys($environment, $service),
                    array_slice($previous, -2),
                ),
                model: $generation->model,
                requester: hash('sha256', 'launchroom-team|'.$generation->team_id.'|'.config()->string('app.key')),
            );

            return ['attempt' => $generation->attempt, 'prompt' => $prompt];
        });

        if ($prepared === null) {
            return false;
        }

        /** @var DockerfilePrompt $prompt */
        $prompt = $prepared['prompt'];

        try {
            $draft = $this->model->draft($prompt);
            $failure = null;
        } catch (DockerfileModelFailed $e) {
            $draft = null;
            $failure = $e;
        }

        return DB::transaction(function () use ($generationId, $prepared, $draft, $failure): bool {
            $generation = DockerfileGeneration::query()->with(['serviceInstance.environment', 'service'])->whereKey($generationId)->lockForUpdate()->first();

            // Cancelled (or advanced by a duplicate job) meanwhile.
            if (! $generation instanceof DockerfileGeneration || $generation->status !== DockerfileGenerationStatus::Drafting || $generation->attempt !== $prepared['attempt']) {
                return false;
            }

            if (! $draft instanceof DockerfileDraft) {
                $this->usage($generation, $failure->inputTokens, $failure->outputTokens);

                if (! $failure->retryable) {
                    $this->attempts->record($generation, ['outcome' => 'model', 'message' => $failure->getMessage()]);
                    $this->attempts->fail($generation, $failure->getMessage());

                    return false;
                }

                return $this->attempts->repairOrFail($generation, 'model', $failure->getMessage());
            }

            $this->usage($generation, $draft->inputTokens, $draft->outputTokens);
            [$environment, $service] = $this->specs($generation);

            $lint = DockerfileLinter::lint(
                $draft->dockerfile,
                $draft->port,
                $service === null ? [] : BuildKey::buildScopedKeys($environment, $service),
                $service === null ? [] : $this->variables->taint($generation->serviceInstance->environment->project, $environment)->taintedKeys($service->slug),
            );

            $this->attempts->record($generation, [
                'dockerfile' => $draft->dockerfile,
                'port' => $draft->port,
                'startCommand' => $draft->startCommand,
                'explanation' => $draft->explanation,
                'confidence' => $draft->confidence->value,
                'inputTokens' => $draft->inputTokens,
                'outputTokens' => $draft->outputTokens,
            ]);

            if (! $lint->passed()) {
                $this->attempts->record($generation, ['lint' => $lint->problems]);

                return $this->attempts->repairOrFail($generation, 'lint', $lint->summary());
            }

            $pinned = $this->pinner->pin($draft->dockerfile, $lint);

            if ($pinned['problems'] !== []) {
                $this->attempts->record($generation, ['lint' => $pinned['problems']]);

                return $this->attempts->repairOrFail($generation, 'lint', implode("\n", array_map(static fn (array $p): string => "line {$p['line']}: {$p['message']}", $pinned['problems'])));
            }

            $generation->forceFill([
                'dockerfile' => $pinned['dockerfile'],
                'port' => $draft->port,
                'start_command' => $draft->startCommand,
                'explanation' => $draft->explanation,
                'confidence' => $draft->confidence,
            ]);
            $this->attempts->record($generation, ['dockerfile' => $pinned['dockerfile']]);

            $source = $generation->service->source;

            if (! $source instanceof GitSource || $service === null || $generation->commit_sha === null) {
                $this->attempts->fail($generation, 'This service no longer builds from a git repository.');

                return false;
            }

            try {
                $task = $this->tasks->verificationBuild($generation->serviceInstance, $environment, $service, $source, $generation->commit_sha, $pinned['dockerfile'], PublicId::generate('dpl'));
            } catch (DomainRuleViolation $e) {
                $this->attempts->fail($generation, $e->getMessage());

                return false;
            }

            $generation->forceFill(['build_task_id' => $task->id, 'run_task_id' => null, 'image' => null]);
            $generation->transitionTo(DockerfileGenerationStatus::Building);

            return false;
        });
    }

    /**
     * @return array{0: EnvironmentSpec, 1: ServiceSpec|null}
     */
    private function specs(DockerfileGeneration $generation): array
    {
        $environment = $this->currentSpec->get($generation->serviceInstance->environment);

        return [$environment, $environment->service($generation->service->public_id)];
    }

    private function usage(DockerfileGeneration $generation, int $input, int $output): void
    {
        $generation->input_tokens += max(0, $input);
        $generation->output_tokens += max(0, $output);
    }
}
