<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Activity\ActivityEntryData;
use App\Data\Activity\ActivityFiltersData;
use App\Enums\ActorType;
use App\Enums\Alerts\AlertStatus;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\PromotionKind;
use App\Models\Alerts\Alert;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;

/**
 * The activity feed (team page in the sidebar, project filter; direction.md
 * §5.4, docs/08): deployments grouped by commit or revision ("mara deployed
 * api and web to production"), rollbacks, promotions, environments created
 * from others, plus a few audit events (project and service created, server
 * approved, member joined). Team-scoped by construction: every source is
 * filtered by the team's projects or `team_id`. Newest first; the page
 * groups by day. Built from domain tables; the audit log keeps the full
 * trail.
 */
final class TeamActivity
{
    private const int LIMIT = 150;

    /** Deployments of one group start within this many seconds of each other. */
    private const int GROUP_WINDOW = 600;

    /**
     * @return list<ActivityEntryData>
     */
    public function get(int $teamId, ActivityFiltersData $filters): array
    {
        $projects = Project::query()
            ->where('team_id', $teamId)
            ->when($filters->project !== null, static fn ($q) => $q->where('public_id', $filters->project))
            ->get(['id', 'public_id', 'name'])
            ->keyBy('id');

        $kinds = $filters->kind === null ? null : ActivityFiltersData::GROUPS[$filters->kind];
        $wants = static fn (string $kind): bool => $kinds === null || in_array($kind, $kinds, true);
        // Alerts (docs/15 §3.6) include servers, so they don't need a project.
        $alerts = $wants('alert') ? $this->alerts($teamId, $projects, $filters->project !== null) : [];

        if ($projects->isEmpty()) {
            return $alerts;
        }

        $environments = Environment::query()
            ->whereIn('project_id', $projects->keys()->all())
            ->get(['id', 'public_id', 'name', 'project_id'])
            ->keyBy('id');

        $entries = $alerts;

        if ($wants('deploy') || $wants('rollback') || $wants('redeploy')) {
            array_push($entries, ...$this->deployments($environments, $projects, $wants));
        }

        if ($wants('promotion') || $wants('created')) {
            array_push($entries, ...$this->promotions($environments, $projects, $wants));
        }

        if ($wants('service') || $wants('project') || $wants('server') || $wants('member')) {
            array_push($entries, ...$this->audit($teamId, $projects, $filters->project !== null, $wants));
        }

        usort($entries, static fn (ActivityEntryData $a, ActivityEntryData $b): int => [$b->at, $b->id] <=> [$a->at, $a->id]);

        return array_slice($this->mergeServices($entries), 0, self::LIMIT);
    }

    /**
     * @param  Collection<int, Environment>  $environments
     * @param  Collection<int, Project>  $projects
     * @param  callable(string): bool  $wants
     * @return list<ActivityEntryData>
     */
    private function deployments(Collection $environments, Collection $projects, callable $wants): array
    {
        $deployments = Deployment::query()
            ->whereIn('environment_id', $environments->keys()->all())
            ->with(['serviceInstance.service:id,name', 'actor:id,name', 'revision:id,number,message'])
            ->latest('id')
            ->limit(self::LIMIT * 3)
            ->get(['id', 'public_id', 'service_instance_id', 'environment_id', 'revision_id', 'trigger', 'commit_sha', 'commit_message', 'commit_author', 'status', 'actor_id', 'queued_at']);

        /** @var list<list<Deployment>> $groups */
        $groups = [];

        foreach ($deployments as $deployment) {
            $last = $groups === [] ? null : $groups[array_key_last($groups)];
            $head = $last[0] ?? null;

            if ($head instanceof Deployment
                && $head->environment_id === $deployment->environment_id
                && $head->revision_id === $deployment->revision_id
                && $head->commit_sha === $deployment->commit_sha
                && $head->trigger === $deployment->trigger
                && abs($head->queued_at->getTimestamp() - $deployment->queued_at->getTimestamp()) <= self::GROUP_WINDOW
                && ! array_any($last ?? [], static fn (Deployment $d): bool => $d->service_instance_id === $deployment->service_instance_id)) {
                $groups[array_key_last($groups)][] = $deployment;

                continue;
            }

            $groups[] = [$deployment];
        }

        $entries = [];

        foreach ($groups as $group) {
            $head = $group[0];
            $kind = match ($head->trigger) {
                DeploymentTrigger::Rollback => 'rollback',
                DeploymentTrigger::Manual => 'redeploy',
                default => 'deploy',
            };

            if (! $wants($kind)) {
                continue;
            }

            $environment = $environments->get($head->environment_id);
            $project = $environment instanceof Environment ? $projects->get($environment->project_id) : null;

            if (! $environment instanceof Environment || ! $project instanceof Project) {
                continue;
            }

            $names = array_values(array_unique(array_map(static fn (Deployment $d): string => $d->serviceInstance->service->name, array_reverse($group))));
            $services = $this->list($names);
            $statuses = array_map(static fn (Deployment $d): DeploymentStatus => $d->status, $group);
            $status = match (true) {
                in_array(DeploymentStatus::Failed, $statuses, true) => 'failed',
                array_any($statuses, static fn (DeploymentStatus $s): bool => ! in_array($s, [DeploymentStatus::Active, DeploymentStatus::Superseded, DeploymentStatus::Failed, DeploymentStatus::Cancelled, DeploymentStatus::Ready], true)) => 'deploying',
                in_array(DeploymentStatus::Ready, $statuses, true) => 'ready',
                array_all($statuses, static fn (DeploymentStatus $s): bool => $s === DeploymentStatus::Cancelled) => 'cancelled',
                default => 'live',
            };
            $message = $head->commit_message !== null ? strtok($head->commit_message, "\n") : $head->revision?->message;

            $entries[] = new ActivityEntryData(
                id: $head->public_id,
                kind: $kind,
                at: $head->queued_at->toIso8601String(),
                actor: $head->actor->name ?? $head->commit_author,
                title: match ($kind) {
                    'rollback' => "rolled back {$services} in {$environment->name}",
                    'redeploy' => "redeployed {$services} in {$environment->name}",
                    default => "deployed {$services} to {$environment->name}",
                },
                detail: is_string($message) && $message !== '' ? $message : ($head->revision !== null ? "Revision {$head->revision->number}" : null),
                environmentId: $environment->public_id,
                environmentName: $environment->name,
                href: count($group) === 1
                    ? "/p/{$project->public_id}/{$environment->public_id}/d/{$head->public_id}"
                    : "/p/{$project->public_id}/deployments?environment={$environment->public_id}",
                projectId: $project->public_id,
                projectName: $project->name,
                status: $status,
                sha: $head->commit_sha !== null ? substr($head->commit_sha, 0, 7) : null,
            );
        }

        return $entries;
    }

    /**
     * @param  Collection<int, Environment>  $environments
     * @param  Collection<int, Project>  $projects
     * @param  callable(string): bool  $wants
     * @return list<ActivityEntryData>
     */
    private function promotions(Collection $environments, Collection $projects, callable $wants): array
    {
        $entries = [];
        $promotions = EnvironmentPromotion::query()
            ->whereIn('project_id', $projects->keys()->all())
            ->with('actor:id,name')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'public_id', 'project_id', 'from_environment_id', 'to_environment_id', 'kind', 'items', 'images', 'actor_id', 'created_at']);

        foreach ($promotions as $promotion) {
            $kind = $promotion->kind === PromotionKind::Created ? 'created' : 'promotion';

            if (! $wants($kind)) {
                continue;
            }

            $from = $environments->get($promotion->from_environment_id);
            $to = $environments->get($promotion->to_environment_id);
            $project = $projects->get($promotion->project_id);
            $count = count($promotion->items);
            $images = count($promotion->images);

            $entries[] = new ActivityEntryData(
                id: $promotion->public_id,
                kind: $kind,
                at: $promotion->created_at->toIso8601String(),
                actor: $promotion->actor?->name,
                title: $kind === 'created'
                    ? 'created '.($to->name ?? 'an environment').' from '.($from->name ?? 'another environment')
                    : "promoted {$count} ".($count === 1 ? 'change' : 'changes').' from '.($from->name ?? '?').' to '.($to->name ?? '?'),
                detail: $images > 0 ? "Also promoted {$images} ".($images === 1 ? 'image' : 'images').'.' : null,
                environmentId: $to?->public_id,
                environmentName: $to?->name,
                href: $project instanceof Project && $to instanceof Environment ? "/p/{$project->public_id}/{$to->public_id}" : null,
                projectId: $project?->public_id,
                projectName: $project?->name,
            );
        }

        return $entries;
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  callable(string): bool  $wants
     * @return list<ActivityEntryData>
     */
    private function audit(int $teamId, Collection $projects, bool $projectOnly, callable $wants): array
    {
        $actions = array_values(array_filter([
            $wants('project') ? AuditAction::ProjectCreated : null,
            $wants('service') ? AuditAction::ServiceCreated : null,
            ! $projectOnly && $wants('server') ? AuditAction::ServerApproved : null,
            ! $projectOnly && $wants('member') ? AuditAction::InvitationAccepted : null,
        ]));

        if ($actions === []) {
            return [];
        }

        $events = AuditEvent::query()
            ->where('team_id', $teamId)
            ->whereIn('action', array_map(static fn (AuditAction $action): string => $action->value, $actions))
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'meta', 'created_at']);

        $users = User::query()
            ->whereIn('id', $events->where('actor_type', ActorType::User)->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        $byPublicId = $projects->keyBy('public_id');
        $servers = Server::query()
            ->where('team_id', $teamId)
            ->whereIn('public_id', $events->where('action', AuditAction::ServerApproved)->pluck('subject_id')->filter()->all())
            ->pluck('name', 'public_id');
        $services = Service::query()
            ->whereIn('project_id', $projects->keys()->all())
            ->whereIn('public_id', $events->where('action', AuditAction::ServiceCreated)->pluck('subject_id')->filter()->all())
            ->pluck('name', 'public_id');
        $entries = [];

        foreach ($events as $event) {
            $actor = $event->actor_type === ActorType::User && $event->actor_id !== null ? $users->get($event->actor_id) : null;
            $projectKey = match ($event->action) {
                AuditAction::ProjectCreated => $event->subject_id,
                AuditAction::ServiceCreated => is_string($event->meta['project'] ?? null) ? $event->meta['project'] : null,
                default => null,
            };
            $project = $projectKey === null ? null : $byPublicId->get($projectKey);

            if (in_array($event->action, [AuditAction::ProjectCreated, AuditAction::ServiceCreated], true) && ! $project instanceof Project) {
                continue; // another project (filtered) or deleted since
            }

            [$kind, $title, $href] = match ($event->action) {
                AuditAction::ProjectCreated => ['project', 'created the project '.$project->name, "/p/{$project->public_id}"],
                AuditAction::ServiceCreated => ['service', 'added '.$this->name($services->get((string) $event->subject_id), 'a service').' to '.$project->name, "/p/{$project->public_id}"],
                AuditAction::ServerApproved => ['server', 'connected the server '.$this->name($servers->get((string) $event->subject_id), 'a server'), $event->subject_id !== null ? "/servers/{$event->subject_id}" : null],
                default => ['member', 'joined the team', null],
            };

            $entries[] = new ActivityEntryData(
                id: 'evt_'.$event->id,
                kind: $kind,
                at: $event->created_at->toIso8601String(),
                actor: is_string($actor) ? $actor : null,
                title: $title,
                detail: null,
                environmentId: null,
                environmentName: null,
                href: $href,
                projectId: $project?->public_id,
                projectName: $project?->name,
            );
        }

        return $entries;
    }

    /**
     * Alerts as activity (docs/15 §3.6): when each opened, open or resolved,
     * with its summary (or how long it lasted). A project filter keeps the
     * project's alerts only.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<ActivityEntryData>
     */
    private function alerts(int $teamId, Collection $projects, bool $projectOnly): array
    {
        $alerts = Alert::query()
            ->where('team_id', $teamId)
            ->when($projectOnly, static fn ($q) => $q->whereIn('project_id', $projects->keys()->all()))
            ->with(['project:id,public_id,name', 'environment:id,public_id,name'])
            ->latest('opened_at')
            ->limit(self::LIMIT)
            ->get();

        return array_values($alerts->map(static function (Alert $alert): ActivityEntryData {
            $resolved = $alert->status === AlertStatus::Resolved && $alert->resolved_at !== null;
            $lasted = $resolved ? CarbonInterval::seconds(max(60, (int) $alert->opened_at->diffInSeconds($alert->resolved_at)))->cascade()->forHumans(['short' => true, 'parts' => 2]) : null;

            return new ActivityEntryData(
                id: $alert->public_id,
                kind: 'alert',
                at: $alert->opened_at->toIso8601String(),
                actor: null,
                title: $alert->title,
                detail: $resolved ? "Resolved after {$lasted}.".($alert->resolve_reason !== null ? ' '.$alert->resolve_reason : '') : $alert->summary,
                environmentId: $alert->environment?->public_id,
                environmentName: $alert->environment?->name,
                href: $alert->href,
                projectId: $alert->project?->public_id,
                projectName: $alert->project?->name,
                status: $resolved ? 'resolved' : 'firing',
                severity: $alert->severity->value,
            );
        })->all());
    }

    /**
     * Services added together (an import, a template) read as one item:
     * "added web, api and postgres to shop".
     *
     * @param  list<ActivityEntryData>  $entries
     * @return list<ActivityEntryData>
     */
    private function mergeServices(array $entries): array
    {
        $out = [];
        /** @var array<int, list<string>> $names */
        $names = [];

        foreach ($entries as $entry) {
            $last = $out === [] ? null : $out[array_key_last($out)];
            $name = $entry->kind === 'service' && preg_match('/^added (.+) to /', $entry->title, $m) === 1 ? $m[1] : null;

            if ($name !== null && $last instanceof ActivityEntryData && $last->kind === 'service'
                && $last->projectId === $entry->projectId && $last->actor === $entry->actor
                && abs(strtotime($last->at) - strtotime($entry->at)) <= self::GROUP_WINDOW) {
                $key = array_key_last($out);
                $names[$key][] = $name;
                $merged = clone $last;
                $merged->title = 'added '.$this->list(array_reverse($names[$key])).' to '.$last->projectName;
                $out[$key] = $merged;

                continue;
            }

            $out[] = $entry;

            if ($name !== null) {
                $names[array_key_last($out)] = [$name];
            }
        }

        return $out;
    }

    private function name(mixed $name, string $fallback): string
    {
        return is_string($name) ? $name : $fallback;
    }

    /**
     * "web", "web and api", "web, api and worker", "web, api and 3 more".
     *
     * @param  list<string>  $names
     */
    private function list(array $names): string
    {
        return match (true) {
            count($names) <= 1 => $names[0] ?? 'a service',
            count($names) === 2 => "{$names[0]} and {$names[1]}",
            count($names) === 3 => "{$names[0]}, {$names[1]} and {$names[2]}",
            default => "{$names[0]}, {$names[1]} and ".(count($names) - 2).' more',
        };
    }
}
