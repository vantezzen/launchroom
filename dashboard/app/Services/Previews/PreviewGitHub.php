<?php

declare(strict_types=1);

namespace App\Services\Previews;

use App\Enums\DeploymentStatus;
use App\Enums\Previews\PreviewProvider;
use App\Models\Deployment;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubPullRequestComment;
use App\Models\Git\GitHubRepository;
use App\Models\Previews\Preview;
use App\Services\GitHub\GitHubClientFactory;
use App\Services\GitHub\GitHubDeploymentReporter;
use App\Services\GitHub\JsonObject;
use App\Services\GitHub\RepositoryCall;
use App\Services\GitHub\TokenScope;
use App\Support\Variables\SystemVariables;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GitHub calls of previews (docs/06 §5 "GitHub feedback", docs/09 R19):
 *
 * - the PR's changed files (focused previews), from the compare API;
 * - whether a PR is still open (nightly orphan sweep);
 * - **one GitHub Deployment per preview environment** (environment
 *   `preview/pr-<n>`, transient) per head commit, whose status aggregates
 *   the preview's deployments (in progress → success with the primary URL,
 *   or failure) and goes `inactive` when the preview is torn down. Check
 *   runs and the PR comment table stay per service
 *   ({@see GitHubDeploymentReporter}); on teardown the table says so.
 *
 * Reporting never throws (logged); lookups return null when unknown.
 */
final readonly class PreviewGitHub
{
    /** GitHub's compare API lists at most 300 files. */
    private const int MAX_COMPARE_FILES = 300;

    public function __construct(
        private GitHubClientFactory $clients,
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

        if (! $repository instanceof GitHubRepository || preg_match('/^[0-9a-f]{40}$/D', $headSha) !== 1) {
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
            $files = array_map(static fn (JsonObject $file): string => $file->string('filename'), $json->objects('files'));

            return count($files) >= self::MAX_COMPARE_FILES ? null : $files;
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

    /**
     * Reports the preview environment's aggregate state to GitHub.
     */
    public function report(Preview $preview): void
    {
        $call = $this->call($preview);

        if (! $call instanceof RepositoryCall) {
            return;
        }

        try {
            Cache::lock('launchroom:github:preview:'.$preview->id, 30)->block(20, function () use ($preview, $call): void {
                $preview->refresh();

                if (! $preview->status->isOpen() || $preview->environment_id === null) {
                    $this->deactivate($preview, $call);

                    return;
                }

                [$state, $url, $description] = $this->aggregate($preview);

                if ($state === null) {
                    return;
                }

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

                $status = ['state' => $state, 'description' => mb_substr($description, 0, 140), 'environment' => $preview->githubEnvironment(), 'auto_inactive' => true];

                if ($url !== null) {
                    $status['environment_url'] = $url;
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
                'state' => 'inactive',
                'description' => 'The preview was removed.',
                'environment' => $preview->githubEnvironment(),
            ]);
            $preview->forceFill(['github_deployment_id' => null, 'github_deployment_sha' => null])->save();
        }

        // The PR comment table: every row of this preview says it's gone.
        $comment = GitHubPullRequestComment::query()
            ->where('github_installation_id', $preview->github_installation_id)
            ->where('github_repo_id', $preview->repository_id)
            ->where('pr_number', $preview->pr_number)
            ->first();

        if (! $comment instanceof GitHubPullRequestComment || $comment->comment_id === null) {
            return;
        }

        $rows = $comment->rows;

        foreach ($rows as $key => $row) {
            if ($row['environment'] === $preview->githubEnvironment()) {
                $rows[$key] = [...$row, 'state' => 'cancelled', 'url' => null];
            }
        }

        $comment->rows = $rows;
        $comment->save();

        $body = GitHubDeploymentReporter::renderComment($rows)."\n_The preview environment was removed ({$preview->status->label()})._\n";
        $call->send('PATCH', "/issues/comments/{$comment->comment_id}", ['body' => $body], [404]);
    }

    /**
     * The preview's aggregate state, also used by GitLab/Gitea previews.
     *
     * @return array{string|null, string|null, string} GitHub state (in_progress/failure/success), primary URL, description
     */
    public function aggregate(Preview $preview): array
    {
        $deployments = Deployment::query()
            ->where('environment_id', $preview->environment_id)
            ->whereNotIn('status', [DeploymentStatus::Superseded->value, DeploymentStatus::Cancelled->value])
            ->with(['serviceInstance.service', 'revision'])
            ->orderByDesc('id')
            ->get()
            ->unique('service_instance_id');

        if ($deployments->isEmpty()) {
            return ['in_progress', null, 'Preparing the preview.'];
        }

        $failed = $deployments->first(static fn (Deployment $deployment): bool => $deployment->status === DeploymentStatus::Failed);

        if ($failed instanceof Deployment) {
            return ['failure', null, "{$failed->serviceInstance->service->name} failed: ".($failed->failure_message ?? 'see launchroom')];
        }

        if (! $deployments->every(static fn (Deployment $deployment): bool => $deployment->status === DeploymentStatus::Active)) {
            return ['in_progress', null, 'Deploying the preview.'];
        }

        $url = null;

        foreach ($deployments as $deployment) {
            $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
            $domain = $spec === null || ! $spec->kind->isWeb() ? null : SystemVariables::primaryDomain($spec);

            if ($domain !== null) {
                $url = 'https://'.$domain;
                break;
            }
        }

        return ['success', $url, 'The preview is live.'];
    }

    private function call(Preview $preview): ?RepositoryCall
    {
        if ($preview->provider !== PreviewProvider::GitHub || $preview->github_installation_id === null) {
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
