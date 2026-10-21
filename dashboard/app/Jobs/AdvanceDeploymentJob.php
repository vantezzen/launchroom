<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Deployments\AdvanceDeployment;
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
 * Event-driven orchestration entry point (docs/04 §6): daemon reports, task
 * completions and the periodic sweep dispatch it. The action row-locks the
 * deployment and is idempotent, so duplicates are harmless.
 */
#[Backoff([2, 10])]
#[Timeout(120)]
#[Tries(3)]
#[UniqueFor(120)]
final class AdvanceDeploymentJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $deploymentId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    public function handle(AdvanceDeployment $advance): void
    {
        $advance->handle($this->deploymentId);
    }
}
