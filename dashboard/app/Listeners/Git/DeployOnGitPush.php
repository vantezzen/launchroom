<?php

declare(strict_types=1);

namespace App\Listeners\Git;

use App\Actions\ConfigAsCode\ApplyConfigFileOnPush;
use App\Actions\Deployments\DeployCommit;
use App\Data\Services\GitSource;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Events\Git\GitPushReceived;
use App\Models\ServiceInstance;
use App\Services\ConfigAsCode\ConfigFileReader;
use Illuminate\Support\Str;

/**
 * Pushes deploy (docs/06 §2): every live service instance whose Git source is
 * this provider + installation/connection + numeric repository id (docs/09 R18) and whose
 * environment trigger matches the ref gets a deployment of the pushed commit
 * (branch: override, else the environment's branch glob; tags: the
 * environment's tag glob).
 *
 * Skipped: preview environments (pull request events deploy those),
 * environments with auto-deploy
 * paused (after a production rollback), and instances whose watch paths
 * match none of the changed files (when the push listed them).
 */
final readonly class DeployOnGitPush
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

        $instances = ServiceInstance::query()
            ->current()
            ->where('team_id', $event->installation->team_id)
            ->where('enabled', true)
            ->whereHas('service', static fn ($query) => $query
                ->whereRaw("source->>'type' = 'git'")
                ->whereRaw("COALESCE(source->>'provider', 'github') = ?", [$event->installation->gitProvider()->value])
                ->whereRaw("(source->>'installationId')::bigint = ?", [$event->installation->id])
                ->whereRaw("(source->>'repositoryId')::bigint = ?", [$event->repositoryId]))
            ->with(['service', 'environment.project'])
            ->orderBy('id')
            ->get();

        /** @var array<int, array{source: GitSource, pushed: list<ServiceInstance>, configOnly: list<ServiceInstance>}> $groups */
        $groups = [];

        foreach ($instances as $instance) {
            $environment = $instance->environment;
            $source = $instance->service->source;

            if (! $source instanceof GitSource || $source->kind() !== $event->installation->gitProvider() || $environment->kind === EnvironmentKind::Preview || $environment->auto_deploy_paused) {
                continue;
            }

            $pattern = $isTag ? $environment->triggers->tagPattern : ($instance->branch_override ?? $environment->triggers->branch);

            if ($pattern === null || $pattern === '' || ! Str::is($pattern, $name)) {
                continue;
            }

            $watched = self::watched($instance->build->watchPaths, $source->rootDirectory, $event->changedFiles);

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
            // The environment's launchroom.json at this commit first: it may
            // fail the push or deploy its changes together with the commit.
            $remaining = $this->configFile->handle($environment, $group['source'], $group['pushed'], $group['configOnly'], $event->headCommit, $event->ref, $trigger);

            foreach ($remaining as $instance) {
                $this->deploy->handle($instance, $event->headCommit, $event->ref, $trigger);
            }
        }
    }

    /**
     * Whether a push touching $changedFiles concerns the instance. Unknown
     * changes (null) always deploy; without watch paths everything counts.
     * Watch paths are repo-relative globs or directory prefixes.
     *
     * @param  list<string>  $watchPaths
     * @param  list<string>|null  $changedFiles
     */
    public static function watched(array $watchPaths, string $rootDirectory, ?array $changedFiles): bool
    {
        if ($changedFiles === null || $watchPaths === []) {
            return true;
        }

        foreach ($changedFiles as $file) {
            foreach ($watchPaths as $path) {
                $path = trim($path);
                $prefix = rtrim($path, '/').'/';

                if ($path !== '' && ($file === $path || str_starts_with($file, $prefix) || Str::is($path, $file))) {
                    return true;
                }
            }
        }

        return false;
    }
}
