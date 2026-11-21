<?php

declare(strict_types=1);

namespace App\Queries\Api;

use App\Data\Api\ListPreviewsQuery;
use App\Data\Api\PreviewResource;
use App\Data\Previews\PreviewUrlData;
use App\Enums\DeploymentStatus;
use App\Enums\Previews\PreviewHealth;
use App\Models\Deployment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Support\Variables\SystemVariables;
use Illuminate\Database\Eloquent\Collection;

/**
 * `GET /projects/{project}/previews` (docs/16 §2.2, D111): a project's
 * previews for scripts and `launchroom preview wait`, open ones first.
 * Share links are left out: they open the preview without signing in.
 */
final readonly class ApiPreviews
{
    private const int LIMIT = 50;

    public function __construct(private ApiResources $resources) {}

    /**
     * @return list<PreviewResource>
     */
    public function for(Project $project, ListPreviewsQuery $query): array
    {
        $settings = PreviewSettings::forProject($project);
        $previews = Preview::query()
            ->where('project_id', $project->id)
            ->when($query->pr !== null, static fn ($q) => $q->where('pr_number', $query->pr))
            ->when($query->open, static fn ($q) => $q->whereNotNull('environment_id'))
            ->with(['environment.lastAppliedRevision', 'installation.app', 'gitConnection'])
            ->orderByRaw("case when status in ('closed', 'expired') then 1 else 0 end")
            ->orderByDesc('pr_number')
            ->limit(self::LIMIT)
            ->get();

        $deployments = Deployment::query()
            ->where('team_id', $project->team_id)
            ->whereIn('environment_id', $previews->pluck('environment_id')->filter()->values()->all())
            ->currentPerInstance()
            ->orderBy('id')
            ->get()
            ->groupBy('environment_id');

        return array_values($previews->map(function (Preview $preview) use ($project, $settings, $deployments): PreviewResource {
            $environment = $preview->environment;
            /** @var Collection<int, Deployment> $current */
            $current = $environment === null ? new Collection : ($deployments[$environment->id] ?? new Collection);
            $urls = [];

            foreach ($environment?->lastAppliedRevision?->spec->services ?? [] as $service) {
                $domain = $service->enabled && $service->kind->isWeb() ? SystemVariables::primaryDomain($service) : null;

                if ($domain !== null) {
                    $urls[] = new PreviewUrlData($service->name, 'https://'.$domain);
                }
            }

            return new PreviewResource(
                id: $preview->public_id,
                number: $preview->pr_number,
                title: $preview->title,
                author: $preview->author_login,
                repository: $preview->repository_name,
                pullRequestUrl: $preview->pullRequestUrl(),
                headRef: $preview->head_ref,
                baseRef: $preview->base_ref,
                headSha: $preview->head_sha,
                isFork: $preview->is_fork,
                status: $preview->status,
                statusReason: $preview->status_reason,
                health: $this->health($environment === null, $current),
                environmentId: $environment?->public_id,
                urls: $urls,
                deployments: array_values($current->map($this->resources->deployment(...))->all()),
                expiresAt: $environment !== null && $settings->expiry_days > 0 ? $preview->expiresAt($settings->expiry_days)->toIso8601String() : null,
                dashboardUrl: route('projects.previews', [$project->public_id]),
            );
        })->all());
    }

    /**
     * @param  Collection<int, Deployment>  $current
     */
    private function health(bool $noEnvironment, Collection $current): PreviewHealth
    {
        $statuses = $current->pluck('status');

        return match (true) {
            $noEnvironment => PreviewHealth::None,
            $statuses->contains(DeploymentStatus::Failed) => PreviewHealth::Failed,
            $statuses->isEmpty() => PreviewHealth::Stopped,
            $statuses->every(static fn (DeploymentStatus $status): bool => $status === DeploymentStatus::Active) => PreviewHealth::Live,
            default => PreviewHealth::Building,
        };
    }
}
