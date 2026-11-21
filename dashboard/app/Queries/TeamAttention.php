<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\AppUpdates\UpdateCandidateData;
use App\Data\Attention\AttentionItemData;
use App\Data\Canvas\ProjectSummaryData;
use App\Enums\AppUpdates\UpdateCheckStatus;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\HealthCheckState;
use App\Enums\NodeStatusReason;
use App\Enums\Permission;
use App\Enums\Previews\PreviewStatus;
use App\Enums\ServerStatus;
use App\Enums\StatusTone;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Queries\Security\FixGroups;
use App\Support\AppUpdates\UpdateChoice;
use App\Support\Links;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * "Needs you" on the projects page (docs/08 §13): what in the team needs
 * the user right now, most urgent first, each with a link to the place that
 * fixes it. Failed services come from the project summaries (no second
 * status pass); then servers that are down, waiting for approval or almost
 * full, known problems live apps log (G12), open security findings that need action, apps more than a
 * year behind (D106), staged changes waiting to
 * deploy, paused auto-deploy and fork pull requests waiting for approval.
 * Healthy, deploying and idle things never show up here.
 */
final readonly class TeamAttention
{
    public function __construct(
        private StagedChangesView $staged,
        private ServerHealth $health,
    ) {}

    /**
     * @param  list<ProjectSummaryData>  $summaries  ProjectSummaries::for($team)
     * @return list<AttentionItemData>
     */
    public function for(Team $team, User $user, array $summaries): array
    {
        if (! $user->hasTeamPermission($team, Permission::View)) {
            return [];
        }

        $items = [
            ...$this->services($summaries),
            ...$this->servers($team),
            ...$this->runtimeFindings($team),
            ...$this->security($team),
            ...$this->outdated($team),
            ...$this->environments($team),
            ...$this->previews($team),
        ];

        // Most urgent first, newest first within a state.
        usort($items, static fn (AttentionItemData $a, AttentionItemData $b): int => [$b->state->rank(), $b->at?->getTimestamp() ?? 0] <=> [$a->state->rank(), $a->at?->getTimestamp() ?? 0]);

        return $items;
    }

    /**
     * @param  list<ProjectSummaryData>  $summaries
     * @return list<AttentionItemData>
     */
    private function services(array $summaries): array
    {
        $items = [];

        foreach ($summaries as $summary) {
            foreach ($summary->environments as $environment) {
                foreach ($environment->problems as $problem) {
                    // The server's own row says it isn't reporting (one thing, one row).
                    if ($problem->status->reason === NodeStatusReason::ServerUnreachable) {
                        continue;
                    }

                    $items[] = new AttentionItemData(
                        key: "service:{$environment->id}:{$problem->serviceId}",
                        kind: 'service',
                        state: $problem->status->state === StatusTone::Failed ? StatusTone::Failed : StatusTone::Warning,
                        title: $problem->sentence,
                        context: $environment->kind === EnvironmentKind::Production ? $summary->name : "{$summary->name}, {$environment->name}",
                        detail: $problem->status->reason === NodeStatusReason::LastDeployFailed ? 'The previous version is still serving.' : null,
                        at: $problem->status->since,
                        href: $problem->href,
                        action: $problem->link,
                    );
                }
            }
        }

        return $items;
    }

    /**
     * Known problems live apps log while they serve (G12): the deploy went
     * green, but visitors hit them. Only while that deployment is live.
     *
     * @return list<AttentionItemData>
     */
    private function runtimeFindings(Team $team): array
    {
        $items = [];
        $deployments = Deployment::query()
            ->where('deployments.team_id', $team->id)
            ->whereNotNull('runtime_finding')
            ->where('status', DeploymentStatus::Active->value)
            ->with(['serviceInstance.service:id,public_id,name,project_id', 'environment:id,public_id,name,kind,project_id', 'environment.project:id,public_id,name'])
            ->latest('id')
            ->limit(20)
            ->get();

        foreach ($deployments as $deployment) {
            $finding = $deployment->runtime_finding;
            $instance = $deployment->serviceInstance;

            if ($finding === null || $instance->active_deployment_id !== $deployment->id) {
                continue;
            }

            $environment = $deployment->environment;
            $project = $environment->project;
            $service = $instance->service;

            $items[] = new AttentionItemData(
                key: "runtime:{$deployment->public_id}",
                kind: 'service',
                state: StatusTone::Warning,
                title: "{$service->name}: {$finding->title}",
                context: $environment->kind === EnvironmentKind::Production ? $project->name : "{$project->name}, {$environment->name}",
                detail: $finding->fix,
                at: CarbonImmutable::parse($finding->at),
                href: route('projects.services.show', [$project->public_id, $environment->public_id, $service->public_id, 'logs'], absolute: false),
                action: 'Open',
            );
        }

        return $items;
    }

    /**
     * @return list<AttentionItemData>
     */
    private function servers(Team $team): array
    {
        $items = [];
        $servers = Server::query()
            ->where('team_id', $team->id)
            ->notRevoked()
            ->orderBy('name')
            ->get();

        foreach ($servers as $server) {
            $href = route('servers.show', [$server->public_id], absolute: false);

            if ($server->status->isDown()) {
                $items[] = new AttentionItemData(
                    key: "server:{$server->public_id}",
                    kind: 'server',
                    state: StatusTone::Failed,
                    title: "{$server->name} is ".strtolower($server->status->label()),
                    context: null,
                    detail: 'Services placed there may be down.',
                    at: $server->last_seen_at,
                    href: $href,
                    action: 'Open',
                );

                continue;
            }

            if ($server->status === ServerStatus::PendingApproval) {
                $items[] = new AttentionItemData(
                    key: "server:{$server->public_id}",
                    kind: 'server',
                    state: StatusTone::Staged,
                    title: "{$server->name} is waiting for approval",
                    context: null,
                    detail: null,
                    at: $server->enrolled_at,
                    href: $href,
                    action: 'Review',
                );

                continue;
            }

            foreach ($this->health->evaluate($server)->checks as $check) {
                if ($check->key === 'disk' && in_array($check->state, [HealthCheckState::Warning, HealthCheckState::Critical], true)) {
                    $items[] = new AttentionItemData(
                        key: "server-disk:{$server->public_id}",
                        kind: 'server',
                        state: $check->state === HealthCheckState::Critical ? StatusTone::Failed : StatusTone::Warning,
                        title: "{$server->name} is running out of disk space",
                        context: null,
                        detail: $check->value,
                        at: null,
                        href: $href,
                        action: 'Open',
                    );
                }
            }
        }

        return $items;
    }

    /**
     * @return list<AttentionItemData>
     */
    private function security(Team $team): array
    {
        // One per package to update, like the sidebar badge and the Security hero (docs/22 §9).
        $count = FixGroups::count($team->id);

        if ($count === 0) {
            return [];
        }

        return [new AttentionItemData(
            key: 'security',
            kind: 'security',
            state: StatusTone::Warning,
            title: $count === 1 ? '1 security fix needed' : "{$count} security fixes needed",
            context: null,
            detail: null,
            at: null,
            href: route('security.index', absolute: false),
            action: 'Review',
        )];
    }

    /**
     * Apps that run a version more than a year old while a newer one is
     * out (D106). Newer releases of current apps stay on their service and
     * in the App updates list; only an app that fell behind needs you.
     *
     * @return list<AttentionItemData>
     */
    private function outdated(Team $team): array
    {
        $checks = AppUpdateCheck::query()
            ->where('team_id', $team->id)
            ->where('status', UpdateCheckStatus::Available->value)
            ->where('running_published_at', '<=', now()->subYear())
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->whereNull('removed_at')->whereNull('stopped_at'))
            ->with(['serviceInstance.service', 'serviceInstance.environment.project'])
            ->oldest('running_published_at')
            ->limit(5)
            ->get();
        $items = [];

        foreach ($checks as $check) {
            $instance = $check->serviceInstance;
            $environment = $instance->environment;
            $newest = UpdateChoice::newest($check->candidates);
            $running = UpdateChoice::runningVersion($check->image);
            $age = $check->running_published_at?->diffForHumans(['parts' => 1, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);

            if (! $newest instanceof UpdateCandidateData || $running === null || $age === null) {
                continue;
            }

            $items[] = new AttentionItemData(
                key: 'update:'.$instance->public_id,
                kind: 'update',
                state: StatusTone::Warning,
                title: "{$instance->service->name} runs {$running}, published {$age} ago",
                context: $environment->kind === EnvironmentKind::Production ? $environment->project->name : "{$environment->project->name}, {$environment->name}",
                detail: "{$newest->version} is available.",
                at: null,
                href: Links::serviceSettings($instance, 'source', 'updates'),
                action: 'Review',
            );
        }

        return $items;
    }

    /**
     * Staged changes waiting to deploy and paused auto-deploy, per
     * persistent environment.
     *
     * @return list<AttentionItemData>
     */
    private function environments(Team $team): array
    {
        $items = [];
        $environments = Environment::query()
            ->where('team_id', $team->id)
            ->where('kind', '!=', EnvironmentKind::Preview->value)
            ->with(['project', 'lastAppliedRevision'])
            ->orderBy('project_id')
            ->orderBy('id')
            ->get();

        foreach ($environments as $environment) {
            $project = $environment->project;
            $context = $environment->kind === EnvironmentKind::Production ? $project->name : "{$project->name}, {$environment->name}";
            $canvas = route('projects.canvas', [$project->public_id, $environment->public_id], absolute: false);

            if ($environment->auto_deploy_paused) {
                $items[] = new AttentionItemData(
                    key: "paused:{$environment->public_id}",
                    kind: 'paused',
                    state: StatusTone::Idle,
                    title: 'Auto-deploy is paused after a rollback',
                    context: $context,
                    detail: 'New pushes won’t go live until you resume it.',
                    at: null,
                    href: $canvas,
                    action: 'Open',
                );
            }

            $count = $this->staged->get($environment)->count;

            if ($count > 0) {
                $items[] = new AttentionItemData(
                    key: "staged:{$environment->public_id}",
                    kind: 'staged',
                    state: StatusTone::Staged,
                    title: $count === 1 ? '1 change is waiting to deploy' : "{$count} changes are waiting to deploy",
                    context: $context,
                    detail: null,
                    at: null,
                    href: route('staged-changes.show', [$project->public_id, $environment->public_id], absolute: false),
                    action: 'Review',
                );
            }
        }

        return $items;
    }

    /**
     * @return list<AttentionItemData>
     */
    private function previews(Team $team): array
    {
        return array_values(Preview::query()
            ->where('team_id', $team->id)
            ->where('status', PreviewStatus::AwaitingApproval->value)
            ->with('project:id,public_id,name')
            ->latest('last_activity_at')
            ->get()
            ->map(static fn (Preview $preview): AttentionItemData => new AttentionItemData(
                key: "preview:{$preview->public_id}",
                kind: 'preview',
                state: StatusTone::Staged,
                title: "Pull request #{$preview->pr_number} from a fork is waiting for approval",
                context: $preview->project->name,
                detail: $preview->title,
                at: $preview->last_activity_at,
                href: route('projects.previews', [$preview->project->public_id], absolute: false),
                action: 'Review',
            ))
            ->all());
    }
}
