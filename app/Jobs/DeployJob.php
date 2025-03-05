<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeployJob implements ShouldQueue
{
    use Queueable;
    public $timeout = 60 * 60; // 1 hour

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
        $manager = new DeploymentManager($this->deployment);
        $manager->deploy();
    }
}
