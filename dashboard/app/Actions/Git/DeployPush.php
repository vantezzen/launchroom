<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\ConfigAsCode\ApplyConfigFileOnPush;
use App\Actions\Deployments\DeployCommit;
use App\Data\Services\GitSource;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Events\Git\GitPushReceived;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Services\ConfigAsCode\ConfigFileReader;
use App\Support\Git\DeployBranch;
use App\Support\Git\WatchPaths;
use Illuminate\Support\Str;

/**
 * Pushes deploy (docs/06 §2): every live service instance sourced from the
 * pushed repository ({@see Service::sourcedFrom()}) whose trigger
 * matches the ref gets a deployment of the pushed commit (branches:
 * {@see DeployBranch::pushPattern()}; tags: the environment's tag glob).
 *
 * Skipped: preview environments (pull request events deploy those),
 * environments with auto-deploy paused (after a production rollback), and
 * instances whose watch paths match none of the changed files (when the push
 * listed them). Per environment, its launchroom.json at the pushed commit is
 * applied first (docs/18 §3.4): it may fail the push or deploy its changes
 * together with the commit, and returns the instances still to deploy.
 */
final readonly class DeployPush
{
    public function __construct(
        private DeployCommit $deploy,
        private ApplyConfigFileOnPush $configFile,
        private ConfigFileReader $configFiles,
    ) {}

    public function handle(GitPushReceived $event): void
    {
        $isTag = str_starts_with($event->ref, 'refs/tags/');
        $name = (string) preg_replace('#^refs/(heads|tags)/#', '', $event->ref);

        if (! $isTag && ! str_starts_with($event->ref, 'refs/heads/')) {
            return;
        }

        $account = $event->installation;
        $instances = ServiceInstance::query()
            ->current()
            ->where('team_id', $account->accountTeamId())
            ->where('enabled', true)
            ->whereHas('service', static fn ($query) => $query->sourcedFrom($account, $event->repositoryId))
            ->with(['service', 'environment.project'])
            ->orderBy('id')
            ->get();

        /** @var array<int, array{source: GitSource, pushed: list<ServiceInstance>, configOnly: list<ServiceInstance>}> $groups */
        $groups = [];

        foreach ($instances as $instance) {
            $environment = $instance->environment;
            $source = $instance->service->source;

            if (! $source instanceof GitSource || $source->provider !== $account->gitProvider() || $environment->kind === EnvironmentKind::Preview || $environment->auto_deploy_paused) {
                continue;
            }

            $pattern = $isTag ? $environment->triggers->tagPattern : DeployBranch::pushPattern($instance);

            if ($pattern === null || $pattern === '' || ! Str::is($pattern, $name)) {
                continue;
            }

            $watched = WatchPaths::matches($instance->build->watchPaths, $event->changedFiles);

            // A change of launchroom.json concerns every service (docs/18 §3.4).
            if (! $watched && ! $this->configFiles->touches($environment->project, $event->changedFiles)) {
                continue;
            }

            $groups[$environment->id] ??= ['source' => $source, 'pushed' => [], 'configOnly' => []];
            $groups[$environment->id][$watched ? 'pushed' : 'configOnly'][] = $instance;
        }

        $trigger = $isTag ? DeploymentTrigger::Tag : DeploymentTrigger::Push;

        foreach ($groups as $group) {
            $environment = ($group['pushed'][0] ?? $group['configOnly'][0])->environment;
            $remaining = $this->configFile->handle($environment, $group['source'], $group['pushed'], $group['configOnly'], $event->headCommit, $event->ref, $trigger);

            foreach ($remaining as $instance) {
                $this->deploy->handle($instance, $event->headCommit, $event->ref, $trigger);
            }
        }
    }
}
