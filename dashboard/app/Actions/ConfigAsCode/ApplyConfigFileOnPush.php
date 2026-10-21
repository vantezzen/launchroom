<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Git\CommitData;
use App\Data\Services\GitSource;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\Git\DeploymentReportState;
use App\Events\CanvasChanged;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Listeners\Git\ReportDeploymentToGitHub;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Services\ConfigAsCode\ConfigApplyOutcome;
use App\Services\ConfigAsCode\ConfigFileReader;
use App\Support\ConfigAsCode\Problems;
use App\Support\Git\GitHubAvatar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A push to a branch an environment deploys carries its launchroom.json
 * (docs/18 §3.4): read at the pushed commit and applied before the push's
 * deployments are created.
 *
 * - No file, or the file is off: the push deploys as before.
 * - Problems: the push fails for this environment; every service it would
 *   deploy gets a failed deployment with the file's errors (GitHub shows a
 *   failed status). Nothing is staged.
 * - Changes and nothing else was staged: one Deploy (revision
 *   "launchroom.json · 3b7d5e1") with the pushed commit pinned on the pushed
 *   services; returns no instances.
 * - Otherwise (no changes, or someone's dashboard edit is staged): the
 *   changes stay staged and the push deploys the code with the last
 *   applied configuration; returns the pushed instances.
 */
final readonly class ApplyConfigFileOnPush
{
    public function __construct(
        private ConfigFileReader $reader,
        private ApplyConfigFile $apply,
        private DeployEnvironment $deploy,
    ) {}

    /**
     * @param  list<ServiceInstance>  $pushed  instances whose watch paths match the push
     * @param  list<ServiceInstance>  $configOnly  instances that match only because the file changed
     * @return list<ServiceInstance> instances the caller still deploys with DeployCommit
     */
    public function handle(Environment $environment, GitSource $pushedSource, array $pushed, array $configOnly, CommitData $commit, string $ref, DeploymentTrigger $trigger): array
    {
        $project = $environment->project;
        $repository = $this->reader->repository($project);

        // Never deployed: pushes don't deploy it yet (docs/06 §2), so they don't read the file either.
        if ($environment->last_applied_revision_id === null || ! $repository instanceof GitSource || ! $this->reader->isConfigRepository($project, $pushedSource)) {
            return $pushed;
        }

        try {
            $content = $this->reader->read($project, $repository, $commit->sha);
        } catch (RuntimeException $e) {
            // Unreadable right now (provider down, token refused): deploy the code as before.
            Log::warning('launchroom.json could not be read; deploying without it.', ['environment' => $environment->public_id, 'sha' => $commit->sha, 'error' => $e->getMessage()]);

            return $pushed;
        }

        $path = $this->reader->settings($project)->path;
        $outcome = $this->apply->handle($environment, $content, $path, $repository, $commit->sha, ConfigTrust::Trusted, AuditActor::system());

        if ($outcome->invalid()) {
            $this->fail($environment, $pushed !== [] ? $pushed : $configOnly, $outcome, $path, $commit, $ref, $trigger);

            return [];
        }

        if (! $outcome->changed || $outcome->otherStaged) {
            if ($outcome->changed) {
                broadcast(new CanvasChanged($environment->public_id));
            }

            return $pushed;
        }

        $commits = [];

        foreach ($pushed as $instance) {
            $commits[$instance->service->public_id] = ['commit' => $commit, 'ref' => $ref];
        }

        try {
            $result = $this->deploy->handle($environment, null, AuditActor::system(), "{$path} · ".substr($commit->sha, 0, 7), $trigger, commits: $commits);
        } catch (DomainRuleViolation|UnresolvedReferences $e) {
            // Broken references or the secrets policy: the changes stay staged
            // and the review page shows why; the code deploys as before.
            Log::warning('launchroom.json changes staged but not deployed.', ['environment' => $environment->public_id, 'error' => $e->getMessage()]);

            return $pushed;
        }

        $outcome->record->status = ConfigFileStatus::Applied;
        $outcome->record->forceFill(['applied_at' => now()])->save();

        foreach ($result->deployments as $deployment) {
            if ($deployment->commit_sha !== null) {
                ReportDeploymentToGitHub::report($deployment, DeploymentReportState::Queued);
            }
        }

        broadcast(new CanvasChanged($environment->public_id));

        return [];
    }

    /**
     * @param  list<ServiceInstance>  $instances
     */
    private function fail(Environment $environment, array $instances, ConfigApplyOutcome $outcome, string $path, CommitData $commit, string $ref, DeploymentTrigger $trigger): void
    {
        DB::transaction(function () use ($environment, $instances, $outcome, $path, $commit, $ref, $trigger): void {
            foreach ($instances as $instance) {
                $deployment = new Deployment;
                $deployment->forceFill([
                    'team_id' => $instance->team_id,
                    'service_instance_id' => $instance->id,
                    'environment_id' => $environment->id,
                    'revision_id' => $environment->last_applied_revision_id,
                    'trigger' => $trigger,
                    'git_ref' => $ref,
                    'commit_sha' => $commit->sha,
                    'commit_message' => mb_substr($commit->message, 0, 4000),
                    'commit_author' => $commit->authorLogin ?? $commit->authorName,
                    'commit_author_avatar_url' => GitHubAvatar::url($commit->authorAvatarUrl, $commit->authorLogin),
                    'queued_at' => now(),
                    'config_errors' => [
                        'path' => $path,
                        'commitSha' => $commit->sha,
                        'repository' => $outcome->record->repository,
                        'errors' => Problems::rows($outcome->errors),
                    ],
                ])->save();
                // Created queued (the column default), failed right away.
                $deployment->refresh();
                $deployment->transitionTo(DeploymentStatus::Failed, DeploymentFailureClass::ConfigError, $outcome->summary);
            }
        });
    }
}
