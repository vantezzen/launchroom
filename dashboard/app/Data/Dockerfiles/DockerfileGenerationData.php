<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileConfidence;
use App\Enums\Dockerfiles\DockerfileGenerationStatus;
use App\Enums\Dockerfiles\DockerfileGenerationTrigger;
use Spatie\LaravelData\Data;

/**
 * A generation as the guided sheet shows it (docs/20 §7): live status,
 * the newest Dockerfile with the previous attempt's for the diff view,
 * the explanation, what was sent and what it cost.
 */
final class DockerfileGenerationData extends Data
{
    /**
     * @param  list<DockerfileAttemptData>  $attempts
     * @param  list<DockerfileFileSentData>  $filesSent
     */
    public function __construct(
        public string $id,
        public DockerfileGenerationStatus $status,
        public DockerfileGenerationTrigger $trigger,
        public string $model,
        public int $attempt,
        public int $maxAttempts,
        public ?string $commitSha,
        public ?string $branch,
        public ?string $dockerfile,
        /** The previous attempt's Dockerfile (diff view), null on the first. */
        public ?string $previousDockerfile,
        public ?int $port,
        public ?string $startCommand,
        public ?string $explanation,
        public ?DockerfileConfidence $confidence,
        public ?string $error,
        public array $attempts,
        public array $filesSent,
        public int $filesListed,
        public int $inputTokens,
        public int $outputTokens,
        /** USD, from launchroom.dockerfile_generation.prices; null for a model without a known price. */
        public ?float $costUsd,
        public ?int $buildMs,
        public ?int $startedAfterMs,
        public string $createdAt,
        public ?string $finishedAt,
        public ?string $acceptedAt,
        /** The service's build settings use this Dockerfile (staged or deployed). */
        public bool $inUse,
        public ?string $pullRequestUrl,
        public bool $canUse,
        public bool $canCancel,
        /** The source's git provider can open a pull request with the file. */
        public bool $canOpenPullRequest,
        public ?string $pullRequestProvider,
        /** Where the file would go in the repository. */
        public string $repositoryPath,
    ) {}
}
