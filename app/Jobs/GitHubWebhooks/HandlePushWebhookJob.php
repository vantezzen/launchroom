<?php

namespace App\Jobs\GitHubWebhooks;

use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\GitHubWebhooks\Models\GitHubWebhookCall;

use function Illuminate\Log\log;

class HandlePushWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public GitHubWebhookCall $webhookCall
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        log('Handling push webhook');
        $payload = $this->webhookCall->payload;

        $repositoryName = $payload['repository']['full_name'];
        $branchName = str_replace('refs/heads/', '', $payload['ref']);
        $commitHash = $payload['head_commit']['id'];

        log("Received push to {$repositoryName} on branch {$branchName} with commit {$commitHash}");

        $projects = Project::where('repository', "https://github.com/{$repositoryName}")->get();

        if ($projects->isEmpty()) {
            log("No projects found for repository {$repositoryName}");

            return;
        }

        foreach ($projects as $project) {
            log("Found project {$project->name} for repository {$repositoryName}");

            $environments = $project->environments()
                ->where('branch', $branchName)
                ->get();
            foreach ($environments as $environment) {
                log("Starting deployment for environment {$environment->name} in project {$project->name}");

                $environment->startDeployment($commitHash);
            }
        }
    }
}
