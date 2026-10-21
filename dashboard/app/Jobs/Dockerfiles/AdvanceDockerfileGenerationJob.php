<?php

declare(strict_types=1);

namespace App\Jobs\Dockerfiles;

use App\Actions\Dockerfiles\AdvanceDockerfileGeneration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Event-driven step of a Dockerfile generation (docs/20 §5): dispatched
 * when one of its tasks finishes and by the sweep. Row-locked and
 * idempotent, so duplicates are harmless.
 */
#[Backoff([2, 10])]
#[Timeout(60)]
#[Tries(3)]
#[UniqueFor(60)]
final class AdvanceDockerfileGenerationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $generationId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    public function handle(AdvanceDockerfileGeneration $advance): void
    {
        $advance->handle($this->generationId);
    }
}
