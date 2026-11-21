<?php

declare(strict_types=1);

namespace App\Jobs\Deployments;

use App\Actions\Deployments\EvaluateDeploymentChecks;
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
 * Reads a waiting push deployment's checks (D111): dispatched when it is
 * queued, by check webhooks and by the one-minute fallback. Idempotent: the
 * action re-checks under the row lock that the deployment still waits.
 */
#[Backoff([10, 30])]
#[Timeout(30)]
#[Tries(3)]
#[UniqueFor(60)]
final class EvaluateDeploymentChecksJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $deploymentId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    public function handle(EvaluateDeploymentChecks $evaluate): void
    {
        $evaluate->handle($this->deploymentId);
    }
}
