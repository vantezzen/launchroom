<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Services\DeploymentManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GetProcessingUsageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Environment $environment,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $deployment = $this->environment->deployments()->latest()->first();
        if (! $deployment) {
            dump('No deployment found for environment '.$this->environment->id);

            return;
        }
        $deploymentManager = new DeploymentManager($deployment);
        $stats = $deploymentManager->getStats();

        $this->environment->processingUsages()->create($stats);
    }
}
