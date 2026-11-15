<?php

declare(strict_types=1);

namespace App\Services\Git\Providers\GitHub;

use App\Enums\Git\DeploymentReportState;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Previews\Preview;
use App\Services\Git\Feedback\PullRequestFeedback;
use App\Support\Git\GitRef;
use App\Support\Json\JsonObject;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GitHub calls of previews (docs/06 §5 "GitHub feedback", docs/09 R19):
 *
 * - the PR's changed files (focused previews), from the compare API;
 * - whether a PR is still open (nightly orphan sweep);
 * - **one GitHub Deployment per preview environment** (environment
 *   `preview/pr-<n>`, transient) per head commit, whose status carries the
 *   preview's aggregate state and goes `inactive` when the preview is torn
 *   down. Check runs and the PR comment stay per service
 *   ({@see GitHubDeploymentReporter}); on teardown the comment says so.
 *
 * Reporting never throws (logged); lookups return null when unknown.
 */
final readonly class GitHubPreviews
{
    public function __construct(
        private GitHubClientFactory $clients,
        private PullRequestFeedback $feedback,
    ) {}

    /**
     * Files changed by the PR (merge base of $baseRef … $headSha), or null
     * when unknown (then every service builds the head).
     *
     * @return list<string>|null
     */
    public function changedFiles(GitHubInstallation $installation, int $repositoryId, string $baseRef, string $headSha): ?array
    {
        $repository = $installation->repositories()->where('github_repo_id', $repositoryId)->first();

        if (! $repository instanceof GitHubRepository || ! GitRef::isSha($headSha)) {
            return null;
        }

        try {
            $response = $this->clients->for($installation->app)->asInstallation(
                $installation->installation_id,
                TokenScope::read($repositoryId),
                'GET',
                "/repos/{$repository->full_name}/compare/".rawurlencode($baseRef).'...'.$headSha,
                query: ['per_page' => 100],
            );
            $json = JsonObject::decode($response->body());

            return PullRequestFeedback::changedFiles(array_map(static fn (JsonObject $file): string => $file->string('filename'), $json->objects('files')));
        } catch (Throwable $e) {
            Log::warning('Could not list a pull request’s changed files', ['repository' => $repositoryId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Whether the PR is open on GitHub (null: unknown).
     */
    public function isOpen(Preview $preview): ?bool
    {
        $call = $this->call($preview);

        if (! $call instanceof RepositoryCall) {
            return null;
        }

        try {
            $response = $call->send('GET', "/pulls/{$preview->pr_number}", accept: [404]);

            return $response->status() === 404 ? false : JsonObject::decode($response->body())->string('state') === 'open';
        } catch (Throwable $e) {
            Log::warning('Could not read a pull request’s state', ['preview' => $preview->public_id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function report(Preview $preview): void
    {
        $call = $this->call($preview);

        if (! $call instanceof RepositoryCall) {
            return;
        }

        try {
            $this->feedback->forPreview($preview, function () use ($preview, $call): void {
                $preview->refresh();

                if (! $preview->status->isOpen() || $preview->environment_id === null) {
                    $this->deactivate($preview, $call);

                    return;
                }

                $state = $this->feedback->previewState($preview);

                if ($preview->github_deployment_id === null || $preview->github_deployment_sha !== $preview->head_sha) {
                    $response = $call->send('POST', '/deployments', [
                        'ref' => $preview->head_sha,
                        'environment' => $preview->githubEnvironment(),
                        'description' => "launchroom preview of #{$preview->pr_number}",
                        'auto_merge' => false,
                        'required_contexts' => [],
                        'transient_environment' => true,
                        'production_environment' => false,
                        'payload' => ['launchroom_preview' => $preview->public_id],
                    ]);
                    $preview->forceFill(['github_deployment_id' => JsonObject::decode($response->body())->int('id'), 'github_deployment_sha' => $preview->head_sha])->save();
                }

                $status = ['state' => $state->state->deploymentStatus(), 'description' => mb_substr($state->description, 0, 140), 'environment' => $preview->githubEnvironment(), 'auto_inactive' => true];

                if ($state->url !== null) {
                    $status['environment_url'] = $state->url;
                }

                $call->send('POST', "/deployments/{$preview->github_deployment_id}/statuses", $status);
            });
        } catch (Throwable $e) {
            Log::warning('Reporting a preview to GitHub failed', ['preview' => $preview->public_id, 'error' => $e->getMessage()]);
        }
    }

    private function deactivate(Preview $preview, RepositoryCall $call): void
    {
        if ($preview->github_deployment_id !== null) {
            $call->send('POST', "/deployments/{$preview->github_deployment_id}/statuses", [
                'state' => DeploymentReportState::Cancelled->deploymentStatus(),
                'description' => 'The preview was removed.',
                'environment' => $preview->githubEnvironment(),
            ]);
            $preview->forceFill(['github_deployment_id' => null, 'github_deployment_sha' => null])->save();
        }

        $this->feedback->retirePreview($call->pullRequestThread($preview->pr_number), $preview);
    }

    private function call(Preview $preview): ?RepositoryCall
    {
        if ($preview->provider !== GitProviderKind::GitHub || $preview->github_installation_id === null) {
            return null;
        }

        $installation = GitHubInstallation::query()->with('app')->where('team_id', $preview->team_id)->whereKey($preview->github_installation_id)->first();
        $repository = $installation?->repositories()->where('github_repo_id', $preview->repository_id)->first();

        if (! $installation instanceof GitHubInstallation || ! $installation->isActive() || ! $repository instanceof GitHubRepository) {
            return null;
        }

        return new RepositoryCall($this->clients->for($installation->app), $installation, $repository);
    }
}
