<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeployJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 60 * 60; // 1 hour

    public $tries = 1; // Don't retry as it may cause failures in the deployment process

    public $deleteWhenMissingModels = true; // Delete the job if the project/environment is deleted

    /**
     * Create a new job instance.
     */
    public function __construct(public Deployment $deployment)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $manager = new DeploymentManager($this->deployment);
            $manager->deploy();
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->deployment->addLogSection(
            'Deployment failed',
            $exception->getMessage(),
            'error'
        );
        $this->deployment->update([
            'status' => 'failed',
            'finished_at' => now(),
        ]);
    }
}
