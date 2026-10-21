<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Services\PlanService;
use App\Models\ServiceInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Starts a plan preview for a service instance off the request path (the
 * branch head lookup talks to GitHub or the git remote).
 */
#[Timeout(60)]
#[Tries(2)]
#[UniqueFor(60)]
final class PlanServiceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $serviceInstanceId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->serviceInstanceId;
    }

    public function handle(PlanService $plan): void
    {
        $instance = ServiceInstance::query()->find($this->serviceInstanceId);

        if ($instance instanceof ServiceInstance) {
            $plan->handle($instance);
        }
    }
}
