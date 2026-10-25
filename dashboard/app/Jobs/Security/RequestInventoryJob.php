<?php

declare(strict_types=1);

namespace App\Jobs\Security;

use App\Actions\Security\RequestInventory;
use App\Models\Deployment;
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
 * A deployment went live: make sure its image is inventoried (docs/22
 * §3.1) and re-evaluate the team (a rollback can bring a version back).
 */
#[Timeout(60)]
#[Tries(3)]
#[Backoff(30)]
#[UniqueFor(600)]
final class RequestInventoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $deploymentId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    public function handle(RequestInventory $request): void
    {
        $deployment = Deployment::query()->whereKey($this->deploymentId)->first();

        if (! $deployment instanceof Deployment) {
            return;
        }

        $request->handle($deployment);
        dispatch(new EvaluateSecurityFindingsJob($deployment->team_id));
    }
}
