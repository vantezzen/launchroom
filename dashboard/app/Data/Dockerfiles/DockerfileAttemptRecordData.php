<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Data;

/**
 * One entry of `dockerfile_generations.attempts` (docs/20 §5): a draft with
 * its Dockerfile and how far it got. Filled step by step
 * (DockerfileAttempts::record).
 */
final class DockerfileAttemptRecordData extends Data
{
    /**
     * @param  list<array{line: int, message: string}>  $lint
     */
    public function __construct(
        public int $n,
        public ?string $startedAt = null,
        public ?string $dockerfile = null,
        public ?int $port = null,
        public ?string $startCommand = null,
        public ?string $explanation = null,
        public ?string $confidence = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public array $lint = [],
        public ?string $buildTask = null,
        public ?int $buildMs = null,
        public ?string $runTask = null,
        public ?int $startedAfterMs = null,
        public ?int $httpStatus = null,
        public ?string $outcome = null,
        public ?string $message = null,
        public ?string $log = null,
    ) {}
}
