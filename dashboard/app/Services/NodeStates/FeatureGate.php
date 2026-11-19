<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Protocol\V1\HealthSpec;
use App\Data\Protocol\V1\IngressSpec;
use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Protocol\V1\VolumeSpec;
use App\Data\Protocol\V1\WorkloadSpec;
use App\Enums\FeatureDowngrade;
use App\Enums\NodeStateField;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

/**
 * Renders for launchroomd versions older than the dashboard (docs/03 §10):
 * one table (NodeStateField) says which optional field needs which daemon
 * feature and what happens without it. The renderer builds the full
 * document and downgrade() removes the DropField/DropItem fields a server
 * didn't announce; for RenderWithout fields the renderer asks accepts()
 * while it builds, because they shape other fields. UI checks of the same
 * fields (can this volume be shared, will it be placed) ask accepts() too.
 */
final class FeatureGate
{
    public static function accepts(Server $server, NodeStateField $field): bool
    {
        return $server->supportsDaemonFeature($field->feature());
    }

    public static function downgrade(NodeState $state, Server $server): NodeState
    {
        $without = array_values(array_filter(
            NodeStateField::cases(),
            static fn (NodeStateField $field): bool => $field->downgrade() !== FeatureDowngrade::RenderWithout && ! self::accepts($server, $field),
        ));

        foreach ($without as $field) {
            match ($field) {
                NodeStateField::LinkProbe => $state->system->linkProbePeers = null,
                NodeStateField::ControlPlane => $state->system->controlPlaneUrl = null,
                NodeStateField::RouteClientAuth => self::eachRoute($state, static function (RouteSpec $route): void {
                    $route->clientAuth = null;
                }),
                NodeStateField::RouteWaf => self::eachRoute($state, static function (RouteSpec $route): void {
                    $route->waf = null;
                }),
                NodeStateField::PreparedVolume => $state->volumes = self::mountedVolumes($state),
                NodeStateField::WorkloadShipLogs => self::eachWorkload($state, static function (WorkloadSpec $workload): void {
                    $workload->shipLogs = false;
                }),
                NodeStateField::WorkloadFiles => self::dropFiles($state, $server),
                NodeStateField::SystemWatchdog => $state->system->watchdog = null,
                NodeStateField::HealthHost => self::eachWorkload($state, static function (WorkloadSpec $workload): void {
                    if ($workload->health instanceof HealthSpec) {
                        $workload->health->host = null;
                    }
                }),
                NodeStateField::HealthRestartAfter => self::eachWorkload($state, static function (WorkloadSpec $workload): void {
                    if ($workload->health instanceof HealthSpec) {
                        $workload->health->restartAfter = null;
                    }
                }),
                NodeStateField::MountInitOwner => self::eachWorkload($state, static function (WorkloadSpec $workload): void {
                    if ($workload->mounts !== null) {
                        $workload->mounts = array_map(static function (array $mount): array {
                            unset($mount['initOwner']);

                            return $mount;
                        }, $workload->mounts);
                    }
                }),
                NodeStateField::IngressTrace => self::dropTrace($state),
                NodeStateField::RouteService => self::eachRoute($state, static function (RouteSpec $route): void {
                    $route->service = null;
                }),
                NodeStateField::RouteUpstreamServer => self::eachRoute($state, static function (RouteSpec $route): void {
                    $route->upstreams = array_map(static function (array $upstream): array {
                        unset($upstream['server']);

                        return $upstream;
                    }, $route->upstreams);
                }),
                // RenderWithout: the renderer already asked accepts() while building.
                NodeStateField::RouteStopped, NodeStateField::SharedVolume, NodeStateField::WorkloadSchedule,
                NodeStateField::WorkloadStop, NodeStateField::WorkloadWalArchive, NodeStateField::IngressDnsProvider => null,
            };
        }

        return $state;
    }

    private static function dropTrace(NodeState $state): void
    {
        if ($state->ingress instanceof IngressSpec) {
            $state->ingress->trace = null;
            $state->ingress = $state->ingress->isEmpty() ? null : $state->ingress;
        }
    }

    /**
     * @param  callable(RouteSpec): void  $change
     */
    private static function eachRoute(NodeState $state, callable $change): void
    {
        foreach ($state->routes as $route) {
            $change($route);
        }
    }

    /**
     * @param  callable(WorkloadSpec): void  $change
     */
    private static function eachWorkload(NodeState $state, callable $change): void
    {
        foreach ($state->workloads as $workload) {
            $change($workload);
        }
    }

    /**
     * @return list<VolumeSpec>
     */
    private static function mountedVolumes(NodeState $state): array
    {
        $mounted = [];

        foreach ($state->workloads as $workload) {
            foreach ($workload->mounts ?? [] as $mount) {
                $mounted[$mount['volume']] = true;
            }
        }

        return array_values(array_filter($state->volumes, static fn (VolumeSpec $volume): bool => isset($mounted[$volume->id])));
    }

    /**
     * Config files are dropped with a warning: the workload starts without
     * them, as it did before launchroomd wrote them (D65).
     */
    private static function dropFiles(NodeState $state, Server $server): void
    {
        $dropped = false;

        self::eachWorkload($state, static function (WorkloadSpec $workload) use (&$dropped): void {
            $dropped = $dropped || $workload->files !== null;
            $workload->files = null;
        });

        if ($dropped) {
            Log::warning('launchroomd on this server does not support config files; update it', ['server' => $server->public_id]);
        }
    }
}
