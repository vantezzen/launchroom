<?php

declare(strict_types=1);

namespace App\Queries;

use App\Contracts\Git\OpensPullRequests;
use App\Data\Dockerfiles\DockerfileAttemptData;
use App\Data\Dockerfiles\DockerfileFileSentData;
use App\Data\Dockerfiles\DockerfileGenerationData;
use App\Data\Dockerfiles\DockerfileGenerationPanelData;
use App\Data\Services\GitSource;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Dockerfiles\DockerfileGenerationGate;
use App\Services\Git\GitProviders;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The sheet's view of generations (docs/20 §7). Never exposes the scrubbed
 * context or log tails: only what was sent (paths, sizes) and why an
 * attempt failed.
 */
final readonly class DockerfileGenerationView
{
    public function __construct(
        private GitProviders $providers,
    ) {}

    public function panel(ServiceInstance $instance, User $user): DockerfileGenerationPanelData
    {
        $instance->loadMissing(['service', 'environment.project.team']);
        $team = $instance->environment->project->team;
        $settings = AiProviderSettings::current();
        $reason = DockerfileGenerationGate::unavailableReason($team, $instance);

        $latest = DockerfileGeneration::query()
            ->where('team_id', $instance->team_id)
            ->where('service_instance_id', $instance->id)
            ->latest('id')
            ->first();

        $plan = $instance->plan ?? [];

        return new DockerfileGenerationPanelData(
            available: $reason === null,
            reason: $reason,
            model: $settings->effectiveModel(),
            providerLabel: $settings->provider->label(),
            canStart: $reason === null && $user->can('update', $instance),
            canEnable: match ($reason) {
                DockerfileGenerationGate::NOT_CONFIGURED => $user->is_instance_owner,
                DockerfileGenerationGate::TEAM_OFF => $user->can('update', $team),
                default => false,
            },
            planFailed: ($plan['status'] ?? null) === 'failed' && ($plan['failureClass'] ?? null) === 'plan_error',
            latest: $latest instanceof DockerfileGeneration ? $this->generation($latest, $user) : null,
        );
    }

    public function generation(DockerfileGeneration $generation, User $user): DockerfileGenerationData
    {
        $generation->loadMissing(['serviceInstance', 'service']);
        $instance = $generation->serviceInstance;
        $source = $generation->service->source;
        $attempts = $generation->attempts ?? [];
        $drafts = array_values(array_filter($attempts, static fn (array $a): bool => is_string($a['dockerfile'] ?? null)));
        $previous = count($drafts) >= 2 ? $drafts[count($drafts) - 2]['dockerfile'] : null;
        $last = $attempts === [] ? [] : $attempts[count($attempts) - 1];
        $canUpdate = Gate::forUser($user)->allows('update', $instance);
        $verified = $generation->status === DockerfileGenerationStatus::Verified;
        $context = $generation->context;

        $provider = null;

        if ($source instanceof GitSource && ! $source->isUnconnectedPlainGit()) {
            try {
                $git = $this->providers->forSource($source);
                $provider = $git instanceof OpensPullRequests ? $source->kind()->label() : null;
            } catch (Throwable) {
                $provider = null;
            }
        }

        return new DockerfileGenerationData(
            id: $generation->public_id,
            status: $generation->status,
            trigger: $generation->trigger,
            model: $generation->model,
            attempt: $generation->attempt,
            maxAttempts: $generation->max_attempts,
            commitSha: $generation->commit_sha,
            branch: $generation->branch,
            dockerfile: $generation->dockerfile ?? (is_string($last['dockerfile'] ?? null) ? $last['dockerfile'] : null),
            previousDockerfile: is_string($previous) ? $previous : null,
            port: $generation->port,
            startCommand: $generation->start_command,
            explanation: $generation->explanation,
            confidence: $generation->confidence,
            error: $generation->error,
            attempts: array_map(static fn (array $a): DockerfileAttemptData => new DockerfileAttemptData(
                n: is_int($a['n'] ?? null) ? $a['n'] : 0,
                outcome: is_string($a['outcome'] ?? null) ? $a['outcome'] : null,
                message: is_string($a['message'] ?? null) ? mb_substr($a['message'], 0, 1200) : null,
                lint: array_values(array_filter(is_array($a['lint'] ?? null) ? $a['lint'] : [], static fn (mixed $p): bool => is_array($p) && is_int($p['line'] ?? null) && is_string($p['message'] ?? null))),
            ), $attempts),
            filesSent: array_map(static fn (array $file): DockerfileFileSentData => new DockerfileFileSentData($file['path'], $file['bytes'], $file['truncated']), $generation->files_sent ?? []),
            filesListed: is_array($context) ? count($context['tree']) : 0,
            inputTokens: $generation->input_tokens,
            outputTokens: $generation->output_tokens,
            costUsd: self::cost($generation->model, $generation->input_tokens, $generation->output_tokens),
            buildMs: $this->lastInt($attempts, 'buildMs'),
            startedAfterMs: $this->lastInt($attempts, 'startedAfterMs'),
            createdAt: ($generation->created_at ?? now())->toIso8601ZuluString(),
            finishedAt: $generation->finished_at?->toIso8601ZuluString(),
            acceptedAt: $generation->accepted_at?->toIso8601ZuluString(),
            inUse: $instance->build->generatedDockerfile === $generation->public_id && $instance->build->builder === 'dockerfile',
            pullRequestUrl: $generation->pull_request_url,
            canUse: $verified && $canUpdate && $instance->removed_at === null,
            canCancel: $generation->status->isRunning() && $canUpdate,
            canOpenPullRequest: $verified && $canUpdate && $provider !== null && $generation->pull_request_url === null,
            pullRequestProvider: $provider,
            repositoryPath: self::repositoryPath($source),
        );
    }

    public static function repositoryPath(mixed $source): string
    {
        $root = $source instanceof GitSource ? trim($source->rootDirectory, '/') : '';

        return $root === '' ? 'Dockerfile' : $root.'/Dockerfile';
    }

    public static function cost(string $model, int $input, int $output): ?float
    {
        $prices = config('launchroom.dockerfile_generation.prices');
        $price = is_array($prices) ? ($prices[$model] ?? null) : null;

        if (! is_array($price) || ! is_numeric($price[0] ?? null) || ! is_numeric($price[1] ?? null)) {
            return null;
        }

        return round(($input * (float) $price[0] + $output * (float) $price[1]) / 1_000_000, 4);
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     */
    private function lastInt(array $attempts, string $key): ?int
    {
        foreach (array_reverse($attempts) as $attempt) {
            if (is_int($attempt[$key] ?? null)) {
                return $attempt[$key];
            }
        }

        return null;
    }
}
