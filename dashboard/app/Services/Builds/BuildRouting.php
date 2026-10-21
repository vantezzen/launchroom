<?php

declare(strict_types=1);

namespace App\Services\Builds;

use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Server;
use App\Scheduling\BuildCandidate;
use App\Scheduling\BuildRouter;
use App\Scheduling\BuildRoutingFailed;
use App\Scheduling\Scheduler;
use App\Services\Deployments\Placer;
use App\Services\Moves\Prebuilds;
use App\Support\ServerPlatform;
use App\Support\Servers\HostCapacity;

/**
 * The database side of build routing (docs/04 §5): which platform a
 * deployment's build targets and which build server it is pinned to
 * (App\Scheduling\BuildRouter decides).
 *
 * - target platforms: the platforms of the online runtime servers the
 *   service may run on (its placement constraints, environment kind);
 * - builders: online servers with the build role, their slots
 *   (`max(1, cores / 4)`), running build/plan tasks and the platforms their
 *   engine emulates (D53).
 */
final readonly class BuildRouting
{
    public function __construct(
        private Placer $placer,
    ) {}

    /**
     * @throws BuildRoutingFailed
     */
    public function platformFor(Deployment $deployment, ServiceSpec $spec): string
    {
        // A build for a server move's target platform (docs/14 §13.3).
        $prebuild = Prebuilds::platform($deployment);

        if ($prebuild !== null) {
            // Throws when no build server builds it.
            return BuildRouter::platform([$prebuild => 1], $this->builders($deployment->team_id));
        }

        $instance = $deployment->serviceInstance;
        $request = $this->placer->request($instance, $spec->scaling, null, max(1, $spec->scaling->replicas));
        $platforms = [];

        foreach ($this->placer->servers($instance->team_id, $instance->id) as $server) {
            if ($server->platform !== null && Scheduler::rejection($server, $request) === null) {
                $platforms[$server->platform] = ($platforms[$server->platform] ?? 0) + 1;
            }
        }

        $active = $instance->active_deployment_id === null ? null : Deployment::query()->find($instance->active_deployment_id);
        $current = $active?->plan['platform'] ?? null;

        return BuildRouter::platform($platforms, $this->builders($instance->team_id), is_string($current) ? $current : null);
    }

    /**
     * The platform of the team's online runtime servers (plan previews: no
     * image is pushed, any build server of the majority platform will do).
     */
    public function teamPlatform(int $teamId): string
    {
        $platforms = [];

        $servers = Server::query()
            ->where('team_id', $teamId)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Runtime->value)
            ->orderBy('id')
            ->get();

        foreach ($servers as $server) {
            $platform = ServerPlatform::of($server);

            if ($platform !== null) {
                $platforms[$platform] = ($platforms[$platform] ?? 0) + 1;
            }
        }

        try {
            return BuildRouter::platform($platforms, $this->builders($teamId));
        } catch (BuildRoutingFailed) {
            return (string) (array_key_first($platforms) ?? 'linux/amd64');
        }
    }

    public function serverFor(int $teamId, string $platform, ?int $stickyId): ?int
    {
        return BuildRouter::server($this->builders($teamId), $platform, $stickyId);
    }

    /**
     * @return list<BuildCandidate>
     */
    public function builders(int $teamId): array
    {
        $servers = Server::query()
            ->where('team_id', $teamId)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Build->value)
            ->orderBy('id')
            ->get();

        $running = DaemonTask::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->where('status', DaemonTaskStatus::Leased->value)
            ->whereIn('type', [DaemonTaskType::Build->value, DaemonTaskType::Plan->value])
            ->groupBy('server_id')
            ->selectRaw('server_id, count(*) as running')
            ->pluck('running', 'server_id')
            ->all();

        return array_values($servers->map(static fn (Server $server): BuildCandidate => new BuildCandidate(
            id: $server->id,
            platform: ServerPlatform::of($server),
            slots: max(1, intdiv(HostCapacity::cpus($server) ?? 4, 4)),
            running: is_numeric($running[$server->id] ?? null) ? (int) $running[$server->id] : 0,
            emulated: ServerPlatform::emulated($server),
        ))->all());
    }
}
