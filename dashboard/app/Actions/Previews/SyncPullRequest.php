<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Data\Previews\PullRequestData;
use App\Enums\Previews\PreviewProvider;
use App\Enums\Previews\PreviewStatus;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Services\Previews\PreviewGate;
use Illuminate\Support\Facades\DB;

/**
 * A pull request event for one project (docs/06 §2, §5):
 *
 * - opened / reopened / synchronize / edited / (un)labeled: the preview row
 *   is created or updated, the gate decides (enabled, target branch, label,
 *   `[skip preview]`, fork policy); allowed PRs start (or queue for a slot)
 *   and new head commits deploy (focused, {@see DeployPreview});
 * - a filter that stops matching (label removed, title opted out) tears the
 *   environment down; closed (or merged) always does.
 * - fork PRs: a `preview` label applied by a user with write access approves
 *   the current head commit (docs/09 R20); the author's own write access
 *   approves automatically.
 */
final readonly class SyncPullRequest
{
    /** Label a maintainer applies to approve a fork's preview. */
    public const string APPROVAL_LABEL = 'preview';

    public function __construct(
        private PreviewGate $gate,
        private StartPreview $start,
        private DeployPreview $deploy,
        private DestroyPreview $destroy,
    ) {}

    public function handle(Project $project, PullRequestData $pr): Preview
    {
        [$preview, $next] = DB::transaction(function () use ($project, $pr): array {
            $preview = Preview::query()
                ->where('project_id', $project->id)
                ->where('provider', $pr->provider->value)
                ->where('repository_id', $pr->repositoryId)
                ->where('pr_number', $pr->number)
                ->lockForUpdate()
                ->first();

            $new = ! $preview instanceof Preview;
            $preview ??= new Preview([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'provider' => $pr->provider,
                'repository_id' => $pr->repositoryId,
                'pr_number' => $pr->number,
                'last_activity_at' => now(),
            ]);
            $headChanged = $preview->head_sha !== $pr->headSha;

            $preview->fill([
                'github_installation_id' => $pr->provider === PreviewProvider::GitHub ? $pr->installationId : null,
                'git_connection_id' => in_array($pr->provider, [PreviewProvider::GitLab, PreviewProvider::Gitea], true) ? $pr->installationId : null,
                'repository_name' => $pr->repositoryName,
                'title' => mb_substr($pr->title, 0, 512),
                'author_login' => mb_substr($pr->authorLogin, 0, 100),
                'head_sha' => $pr->headSha,
                'head_ref' => mb_substr($pr->headRef, 0, 255),
                'base_ref' => mb_substr($pr->baseRef, 0, 255),
                'is_fork' => $pr->isFork,
                'author_permission' => $pr->authorPermission ?? $preview->author_permission,
                'labels' => array_slice($pr->labels, 0, 100),
            ]);

            if ($pr->isCodeEvent() || $new) {
                $preview->forceFill([
                    'changed_files' => $pr->changedFiles === null ? null : array_slice($pr->changedFiles, 0, 3000),
                    'last_activity_at' => now(),
                ]);
            }

            if ($pr->isFork && $pr->label === self::APPROVAL_LABEL && PullRequestData::hasWriteAccess($pr->senderPermission)) {
                $preview->forceFill(['approved_by' => null, 'approved_at' => now(), 'approved_sha' => $pr->headSha]);
            }

            if ($new) {
                $preview->status = PreviewStatus::Queued;
            }

            $preview->save();
            $preview->setRelation('project', $project);

            if ($pr->isClose()) {
                return [$preview, $preview->status->isOpen() ? 'close' : null];
            }

            // A reopened PR starts over.
            if (! $preview->status->isOpen()) {
                $preview->transitionTo(PreviewStatus::Queued, 'Reopened.');
            }

            $decision = $this->gate->decide(PreviewSettings::forProject($project), $preview);

            if ($decision !== null) {
                [$status, $reason] = $decision;

                if ($preview->environment_id !== null && $status === PreviewStatus::Skipped) {
                    return [$preview, 'skip:'.$reason];
                }

                $preview->transitionTo($status, $reason);

                return [$preview, null];
            }

            if ($preview->environment_id !== null) {
                if (! $headChanged && in_array($preview->status, [PreviewStatus::Preparing, PreviewStatus::Running], true)) {
                    return [$preview, null];
                }

                if (! in_array($preview->status, [PreviewStatus::Preparing, PreviewStatus::Running], true)) {
                    $preview->transitionTo(PreviewStatus::Preparing, 'Deploying new commits.');
                }

                return [$preview, 'deploy'];
            }

            return [$preview, 'start'];
        });

        match (true) {
            $next === 'close' => $this->destroy->handle($preview, PreviewStatus::Closed, $pr->merged ? 'The pull request was merged.' : 'The pull request was closed.'),
            is_string($next) && str_starts_with($next, 'skip:') => $this->destroy->handle($preview, PreviewStatus::Skipped, substr($next, 5)),
            $next === 'deploy' => $this->deploy->handle($preview),
            $next === 'start' => $this->start->handle($preview),
            default => null,
        };

        return $preview->refresh();
    }
}
