<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Attention\AttentionItemData;
use App\Data\Canvas\ProjectSummaryData;
use App\Enums\AttentionState;
use App\Enums\CanvasNodeState;
use App\Enums\EnvironmentKind;
use App\Enums\HealthCheckState;
use App\Enums\Permission;
use App\Enums\Previews\PreviewStatus;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\ServerStatus;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Security\SecurityFinding;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

/**
 * "Needs you" on the projects page (docs/08 §13): what in the team needs
 * the user right now, most urgent first, each with a link to the place that
 * fixes it. Failed services come from the project summaries (no second
 * status pass); then servers that are down, waiting for approval or almost
 * full, open security findings that need action, staged changes waiting to
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
            ...$this->security($team),
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
                    $failedDeploy = in_array($problem->status->label, ['Failed', 'Last deploy failed'], true);
                    $items[] = new AttentionItemData(
                        key: "service:{$environment->id}:{$problem->serviceId}",
                        kind: 'service',
                        state: $problem->status->state === CanvasNodeState::Failed ? AttentionState::Failed : AttentionState::Warning,
                        title: $problem->sentence,
                        context: $environment->kind === EnvironmentKind::Production ? $summary->name : "{$summary->name}, {$environment->name}",
                        detail: $problem->status->label === 'Last deploy failed' ? 'The previous version is still serving.' : null,
                        at: $problem->status->since,
                        href: $problem->href,
                        action: $failedDeploy ? 'See why' : 'Open',
                    );
                }
            }
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
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value, ServerStatus::PendingApproval->value])
            ->orderBy('name')
            ->get();

        foreach ($servers as $server) {
            $href = route('servers.show', [$server->public_id], absolute: false);

            if (in_array($server->status, [ServerStatus::Unreachable, ServerStatus::Offline], true)) {
                $items[] = new AttentionItemData(
                    key: "server:{$server->public_id}",
                    kind: 'server',
                    state: AttentionState::Failed,
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
                    state: AttentionState::Staged,
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
                        state: $check->state === HealthCheckState::Critical ? AttentionState::Failed : AttentionState::Warning,
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
        $count = SecurityFinding::query()
            ->where('team_id', $team->id)
            ->where('status', SecurityFindingStatus::Open->value)
            ->where('level', FindingLevel::ActionNeeded->value)
            ->count();

        if ($count === 0) {
            return [];
        }

        return [new AttentionItemData(
            key: 'security',
            kind: 'security',
            state: AttentionState::Warning,
            title: $count === 1 ? '1 security finding needs action' : "{$count} security findings need action",
            context: null,
            detail: null,
            at: null,
            href: route('security.index', absolute: false),
            action: 'Review',
        )];
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
                    state: AttentionState::Idle,
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
                    state: AttentionState::Staged,
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
                state: AttentionState::Staged,
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
