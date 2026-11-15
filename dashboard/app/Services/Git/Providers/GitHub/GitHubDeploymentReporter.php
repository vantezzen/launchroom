<?php

declare(strict_types=1);

namespace App\Services\Git\Providers\GitHub;

use App\Data\Git\DeploymentStatusReport;
use App\Enums\Git\DeploymentReportState;
use App\Models\Git\GitHubDeploymentReport;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Services\Git\Feedback\PullRequestFeedback;
use App\Support\Git\GitRef;
use App\Support\Json\JsonObject;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GitHub feedback for deployments (docs/06 §5): a GitHub Deployment with
 * statuses (environment name and URL), a check run per service, and for
 * previews one PR comment table updated in place.
 *
 * Reporting never affects a deployment: every step catches and logs its own
 * failure, and {@see report()} never throws (docs/10 §2.1 listeners).
 */
final readonly class GitHubDeploymentReporter
{
    public function __construct(
        private GitHubClientFactory $clients,
        private PullRequestFeedback $feedback,
    ) {}

    public function report(DeploymentStatusReport $report): void
    {
        try {
            $this->send($report);
        } catch (Throwable $e) {
            $this->failed('report', $report, $e);
        }
    }

    private function send(DeploymentStatusReport $report): void
    {
        if (! GitRef::isSha($report->commitSha)) {
            return;
        }

        $installation = GitHubInstallation::query()
            ->with('app')
            ->where('team_id', $report->teamId)
            ->where('id', $report->installationId)
            ->first();

        if (! $installation instanceof GitHubInstallation || ! $installation->isActive()) {
            return;
        }

        $repository = $installation->repositories()->where('github_repo_id', $report->repositoryId)->first();

        if (! $repository instanceof GitHubRepository) {
            return;
        }

        $client = $this->clients->for($installation->app);

        // One report per deployment at a time, so concurrent status changes
        // don't create two GitHub deployments or check runs.
        Cache::lock('launchroom:github:report:'.$report->deploymentKey, 30)->block(20, function () use ($report, $installation, $repository, $client): void {
            $record = GitHubDeploymentReport::query()->firstOrCreate(
                ['deployment_key' => $report->deploymentKey],
                [
                    'team_id' => $installation->team_id,
                    'github_installation_id' => $installation->id,
                    'github_repo_id' => $repository->github_repo_id,
                ],
            );

            $call = new RepositoryCall($client, $installation, $repository);

            if ($report->deploymentApi) {
                $this->step('deployment', $report, fn () => $this->reportDeployment($report, $record, $call));
            }

            $this->step('check_run', $report, fn () => $this->reportCheckRun($report, $record, $call));
        });

        if ($report->pullRequestNumber !== null) {
            $thread = new RepositoryCall($client, $installation, $repository)->pullRequestThread($report->pullRequestNumber);
            $this->step('pull_request_comment', $report, fn () => $this->feedback->recordDeployment($thread, $report));
        }
    }

    private function reportDeployment(DeploymentStatusReport $report, GitHubDeploymentReport $record, RepositoryCall $call): void
    {
        if ($record->github_deployment_id === null) {
            $response = $call->send('POST', '/deployments', [
                'ref' => $report->commitSha,
                'environment' => $report->environmentName,
                'description' => $this->truncate("launchroom: {$report->serviceName}", 140),
                'auto_merge' => false,
                'required_contexts' => [],
                'transient_environment' => $report->transientEnvironment,
                'production_environment' => $report->productionEnvironment,
                'payload' => ['launchroom_deployment' => $report->deploymentKey, 'service' => $report->serviceName],
            ]);

            $record->github_deployment_id = JsonObject::decode($response->body())->int('id');
            $record->save();
        }

        $status = [
            'state' => $report->state->deploymentStatus(),
            'log_url' => $report->deploymentUrl,
            'description' => $this->truncate($report->description ?? $report->state->label(), 140),
            'environment' => $report->environmentName,
            'auto_inactive' => true,
        ];

        if ($report->environmentUrl !== null) {
            $status['environment_url'] = $report->environmentUrl;
        }

        $call->send('POST', "/deployments/{$record->github_deployment_id}/statuses", $status);
    }

    private function reportCheckRun(DeploymentStatusReport $report, GitHubDeploymentReport $record, RepositoryCall $call): void
    {
        $body = [
            'status' => $report->state->checkRunStatus(),
            'details_url' => $report->deploymentUrl,
            'output' => [
                'title' => $this->checkRunTitle($report),
                'summary' => $this->checkRunSummary($report),
            ],
        ];

        $conclusion = $report->state->checkRunConclusion();

        if ($conclusion !== null) {
            $body['conclusion'] = $conclusion;
            $body['completed_at'] = CarbonImmutable::now()->toIso8601ZuluString();
        }

        if ($record->check_run_id !== null) {
            $call->send('PATCH', "/check-runs/{$record->check_run_id}", $body);

            return;
        }

        $response = $call->send('POST', '/check-runs', [
            'name' => $this->truncate("launchroom: {$report->serviceName} ({$report->environmentName})", 100),
            'head_sha' => $report->commitSha,
            'external_id' => $report->deploymentKey,
            ...($report->state === DeploymentReportState::InProgress ? ['started_at' => CarbonImmutable::now()->toIso8601ZuluString()] : []),
            ...$body,
        ]);

        $record->check_run_id = JsonObject::decode($response->body())->int('id');
        $record->save();
    }

    private function checkRunTitle(DeploymentStatusReport $report): string
    {
        return $this->truncate(match ($report->state) {
            DeploymentReportState::Queued => "{$report->serviceName} is queued for {$report->environmentName}",
            DeploymentReportState::InProgress => "Deploying {$report->serviceName} to {$report->environmentName}",
            DeploymentReportState::Success => "{$report->serviceName} is live in {$report->environmentName}",
            DeploymentReportState::Failure => "Deploying {$report->serviceName} to {$report->environmentName} failed",
            DeploymentReportState::Cancelled => "Deployment of {$report->serviceName} was cancelled",
        }, 255);
    }

    private function checkRunSummary(DeploymentStatusReport $report): string
    {
        $lines = [];

        if ($report->description !== null && $report->description !== '') {
            $lines[] = $this->truncate($report->description, 2000);
            $lines[] = '';
        }

        if ($report->environmentUrl !== null && $report->state === DeploymentReportState::Success) {
            $lines[] = "Live at {$report->environmentUrl}";
        }

        $lines[] = "[Open the deployment in launchroom]({$report->deploymentUrl})";

        return implode("\n", $lines);
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }

    /**
     * @param  Closure(): void  $step
     */
    private function step(string $name, DeploymentStatusReport $report, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            $this->failed($name, $report, $e);
        }
    }

    private function failed(string $step, DeploymentStatusReport $report, Throwable $e): void
    {
        // Messages carry method, path and GitHub's error text only, never tokens.
        Log::warning('Reporting a deployment to GitHub failed', [
            'step' => $step,
            'deployment' => $report->deploymentKey,
            'repository' => $report->repositoryId,
            'error' => $e->getMessage(),
        ]);
    }
}
