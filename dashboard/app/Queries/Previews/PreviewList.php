<?php

declare(strict_types=1);

namespace App\Queries\Previews;

use App\Data\Previews\PreviewData;
use App\Data\Previews\PreviewDatabaseData;
use App\Data\Previews\PreviewLinkData;
use App\Data\Previews\PreviewUrlData;
use App\Data\Previews\ShareLinkData;
use App\Enums\DeploymentStatus;
use App\Enums\Previews\PreviewProvider;
use App\Enums\PreviewStrategy;
use App\Models\Deployment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Previews\PreviewShareLink;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Services\Previews\PreviewAccess;
use App\Support\Variables\SystemVariables;

/**
 * The previews list of a project (docs/08): open previews first, then the
 * most recent closed ones. A fixed number of queries.
 */
final readonly class PreviewList
{
    public function __construct(
        private PreviewAccess $access,
    ) {}

    /**
     * @return list<PreviewData>
     */
    public function for(Project $project, bool $withShareUrls): array
    {
        $settings = PreviewSettings::forProject($project);
        $previews = Preview::query()
            ->where('project_id', $project->id)
            ->with(['environment.lastAppliedRevision', 'environment.baseEnvironment', 'approver', 'shareLinks.creator', 'installation.app', 'gitConnection'])
            ->orderByRaw("case when status in ('closed', 'expired') then 1 else 0 end")
            ->orderByDesc('pr_number')
            ->limit(100)
            ->get();

        $environmentIds = $previews->pluck('environment_id')->filter()->values()->all();
        $deployments = Deployment::query()
            ->whereIn('environment_id', $environmentIds)
            ->whereNotIn('status', [DeploymentStatus::Superseded->value, DeploymentStatus::Cancelled->value])
            ->orderByDesc('id')
            ->get(['id', 'environment_id', 'service_instance_id', 'status'])
            ->unique('service_instance_id')
            ->groupBy('environment_id');
        $instances = ServiceInstance::query()
            ->whereIn('environment_id', $environmentIds)
            ->with(['service', 'previewCloneSource.environment', 'previewCloneSource.service'])
            ->get()
            ->groupBy('environment_id');

        $list = [];

        foreach ($previews as $preview) {
            $environment = $preview->environment;
            $spec = $environment?->lastAppliedRevision?->spec;
            $urls = [];

            foreach ($spec->services ?? [] as $service) {
                $domain = $service->enabled && $service->kind->isWeb() ? SystemVariables::primaryDomain($service) : null;

                if ($domain !== null) {
                    $urls[] = new PreviewUrlData($service->name, 'https://'.$domain);
                }
            }

            $databases = [];

            foreach ($environment === null ? [] : ($instances[$environment->id] ?? []) as $instance) {
                /** @var ServiceInstance $instance */
                if (! $instance->service->kind->isStateful() || $instance->preview_strategy === null) {
                    continue;
                }

                $states = $preview->data['databases'] ?? [];
                $state = is_array($states) ? ($states[$instance->service->public_id] ?? null) : null;
                $source = match ($instance->preview_strategy) {
                    PreviewStrategy::Clone => $instance->previewCloneSource === null ? null : $instance->previewCloneSource->environment->name.' / '.$instance->previewCloneSource->service->name,
                    PreviewStrategy::Share => $environment?->baseEnvironment?->name,
                    PreviewStrategy::NewEmpty => null,
                };
                $databases[] = new PreviewDatabaseData(
                    $instance->service->name,
                    $instance->preview_strategy,
                    is_array($state) && is_string($state['state'] ?? null) ? $state['state'] : null,
                    is_array($state) && is_string($state['message'] ?? null) ? $state['message'] : null,
                    $source,
                    is_array($state) && is_string($state['downgradedFrom'] ?? null) ? PreviewStrategy::tryFrom($state['downgradedFrom']) : null,
                    is_array($state) && is_string($state['downgradeReason'] ?? null) ? $state['downgradeReason'] : null,
                );
            }

            $statuses = $environment === null ? collect() : ($deployments[$environment->id] ?? collect())->pluck('status');

            $list[] = new PreviewData(
                id: $preview->public_id,
                number: $preview->pr_number,
                title: $preview->title,
                author: $preview->author_login,
                repository: $preview->repository_name,
                pullRequestUrl: match (true) {
                    $preview->provider === PreviewProvider::GitHub && $preview->installation !== null => rtrim($preview->installation->app->web_url, '/').'/'.$preview->repository_name.'/pull/'.$preview->pr_number,
                    // GitLab merge requests and Gitea/Forgejo pull requests (docs/19).
                    $preview->provider === PreviewProvider::GitLab && $preview->gitConnection !== null => $preview->gitConnection->origin().'/'.$preview->repository_name.'/-/merge_requests/'.$preview->pr_number,
                    $preview->provider === PreviewProvider::Gitea && $preview->gitConnection !== null => $preview->gitConnection->origin().'/'.$preview->repository_name.'/pulls/'.$preview->pr_number,
                    default => null,
                },
                headRef: $preview->head_ref,
                baseRef: $preview->base_ref,
                headSha: substr($preview->head_sha, 0, 7),
                headCommit: $preview->head_sha,
                isFork: $preview->is_fork,
                status: $preview->status,
                statusLabel: $preview->status->label(),
                statusReason: $preview->status_reason,
                environmentId: $environment?->public_id,
                health: match (true) {
                    $environment === null => 'none',
                    $statuses->contains(DeploymentStatus::Failed) => 'failed',
                    $statuses->isNotEmpty() && $statuses->every(static fn (DeploymentStatus $status): bool => $status === DeploymentStatus::Active) => 'live',
                    $statuses->isEmpty() => 'stopped',
                    default => 'building',
                },
                urls: $urls,
                databases: $databases,
                secretsWithheld: $environment !== null && $environment->secrets_withheld,
                approvedBy: $preview->approved_at === null ? null : ($preview->approver->name ?? 'a maintainer on GitHub'),
                expiresAt: $environment !== null && $settings->expiry_days > 0 ? $preview->expiresAt($settings->expiry_days)->toIso8601String() : null,
                lastActivityAt: $preview->last_activity_at->toIso8601String(),
                shareLinks: array_values($preview->shareLinks
                    ->sortByDesc('id')
                    ->map(fn (PreviewShareLink $link): ShareLinkData => new ShareLinkData(
                        $link->public_id,
                        $link->label,
                        $link->expires_at->toIso8601String(),
                        $link->isUsable(),
                        $link->last_used_at?->toIso8601String(),
                        $link->creator?->name,
                        $withShareUrls && $link->isUsable() && $environment !== null && $urls !== []
                            ? $urls[0]->url.'/?'.PreviewAccess::TOKEN_PARAMETER.'='.$this->access->shareToken($link, $environment)
                            : null,
                    ))
                    ->values()
                    ->all()),
                protected: $settings->protection,
            );
        }

        return $list;
    }

    /**
     * Open preview environments for the environment switcher.
     *
     * @return list<PreviewLinkData>
     */
    public function links(Project $project): array
    {
        return array_values(Preview::query()
            ->where('project_id', $project->id)
            ->whereNotNull('environment_id')
            ->with('environment:id,public_id')
            ->orderByDesc('pr_number')
            ->limit(50)
            ->get()
            ->filter(static fn (Preview $preview): bool => $preview->environment !== null)
            ->map(static fn (Preview $preview): PreviewLinkData => new PreviewLinkData((string) $preview->environment?->public_id, $preview->pr_number, $preview->title, $preview->status))
            ->all());
    }
}
