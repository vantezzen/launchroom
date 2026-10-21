<?php

declare(strict_types=1);

namespace App\Services\Previews;

use App\Data\Protocol\V1\NetworkSpec;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Specs\EnvironmentSpec;
use App\Enums\EnvironmentKind;
use App\Enums\WorkloadRole;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Previews\PreviewSettings;
use App\Models\Server;
use App\Models\Service;
use App\Services\Instance\DashboardOrigin;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Hostname;
use Illuminate\Support\Facades\Log;

/**
 * Preview-specific parts of a server's NodeState (docs/06 §5, docs/05 §4–5),
 * used by App\Services\NodeStates\NodeStateContent for one render:
 *
 * - route **protection** (forward auth to the dashboard's `/auth/preview`)
 *   for every host of a preview environment whose project protects previews;
 * - **immutable per-deployment routes** `{service}-{shortid}.…` for every
 *   deployment with routable workloads on this edge (any environment),
 *   on-demand TLS (issued only when visited) and never indexed;
 * - the base environment's network for workloads of previews that **share**
 *   a base database (attached without aliases, after the primary network).
 *
 * Memoizes per render (not across renders: build one per NodeState).
 */
final class PreviewRoutes
{
    public const string STRIP_HEADER = 'X-Launchroom-Bypass';

    /** @var array<int, array{forwardAuth: array{url: string, stripHeaders: list<string>}}|false|null> */
    private array $protection = [];

    /** @var array<string, Environment|null> */
    private array $bases = [];

    public function __construct(
        private readonly PreviewUrls $urls,
        private readonly Server $server,
    ) {}

    /**
     * The route protection for hosts of $environment: null when unprotected,
     * false when it must be protected but can't be (no valid auth URL): such
     * routes are not rendered at all (fail closed).
     *
     * @return array{forwardAuth: array{url: string, stripHeaders: list<string>}}|false|null
     */
    public function protectionFor(Environment $environment): array|false|null
    {
        if ($environment->kind !== EnvironmentKind::Preview) {
            return null;
        }

        if (array_key_exists($environment->id, $this->protection)) {
            return $this->protection[$environment->id];
        }

        $settings = PreviewSettings::query()->where('project_id', $environment->project_id)->first();

        if ($settings instanceof PreviewSettings && ! $settings->protection) {
            return $this->protection[$environment->id] = null;
        }

        $url = $this->authUrl();

        if ($url === null) {
            Log::warning('Preview routes are not rendered: no valid forward-auth URL (launchroom.previews.auth_url / APP_URL).', ['server' => $this->server->public_id]);

            return $this->protection[$environment->id] = false;
        }

        return $this->protection[$environment->id] = ['forwardAuth' => ['url' => $url, 'stripHeaders' => [self::STRIP_HEADER]]];
    }

    /**
     * Routes for the immutable URLs of live deployments: every edge serves
     * every one (docs/05 §5), from this server's rendered workloads and from
     * replicas on other servers through their mesh-published address.
     *
     * @param  list<Placement>  $placements  this server's placements
     * @param  array<string, int>  $rendered  workload ids rendered in this NodeState
     * @param  list<Placement>  $remote  placements on other servers
     * @param  (callable(Placement): ?string)|null  $address  mesh address of a remote placement
     * @return array<string, RouteSpec> keyed by hostname
     */
    public function deploymentRoutes(array $placements, array $rendered, array $remote = [], ?callable $address = null): array
    {
        /** @var array<int, array{deployment: Deployment, service: Service, upstreams: list<array{workload: string, port: int}|array{address: string}>}> $byDeployment */
        $byDeployment = [];
        $local = array_flip(array_map(static fn (Placement $placement): int => $placement->id, $placements));

        foreach ([...$placements, ...$remote] as $placement) {
            $deployment = $placement->deployment;
            $spec = $deployment->revision?->spec->service($placement->serviceInstance->service->public_id);
            $port = $spec === null ? null : EffectiveDeploy::for($spec, $deployment)->port;

            // The instance's active deployment (from the routing step on),
            // staged ("ready") or draining ones: a rollout in progress gets
            // its URL once it passed its health checks.
            $live = $placement->role === WorkloadRole::Draining
                || $placement->deployment_id === $placement->serviceInstance->active_deployment_id
                || $deployment->status->value === 'ready';

            if (! $live || $spec === null || $port === null || ! $spec->kind->isWeb()) {
                continue;
            }

            if (isset($local[$placement->id])) {
                if (! isset($rendered[$placement->workload_id])) {
                    continue;
                }

                $upstream = ['workload' => $placement->workload_id, 'port' => $port];
            } else {
                $mesh = $address === null ? null : $address($placement);

                if ($mesh === null) {
                    continue;
                }

                $upstream = ['address' => $mesh];
            }

            $byDeployment[$deployment->id] ??= ['deployment' => $deployment, 'service' => $placement->serviceInstance->service, 'upstreams' => []];
            $byDeployment[$deployment->id]['upstreams'][] = $upstream;
        }

        $routes = [];

        foreach ($byDeployment as ['deployment' => $deployment, 'service' => $service, 'upstreams' => $upstreams]) {
            $environment = $deployment->environment;
            $host = $this->urls->deploymentHostname($service, $deployment, $environment->project);
            $protection = $this->protectionFor($environment);

            if ($host === null || $protection === false) {
                continue;
            }

            $routes[$host] = new RouteSpec(
                id: 'rt_'.substr($deployment->public_id, strlen('dpl_')),
                hosts: [$host],
                tls: Hostname::isLocal($host) ? 'internal' : 'on_demand',
                upstreams: $upstreams,
                headers: ['X-Robots-Tag' => 'noindex'],
                protection: $protection,
            );
        }

        return $routes;
    }

    /**
     * Extra networks of a workload: the base environment's network when its
     * revision shares a base database (attached without aliases).
     *
     * @return list<array{network: NetworkSpec, aliases: list<string>}>
     */
    public function sharedNetworks(EnvironmentSpec $spec): array
    {
        $networks = [];

        // Fork previews never join a base network (docs/09 R20), also not
        // from a revision recorded before that rule.
        $own = $spec->sharedServices === [] ? null : ($this->bases[$spec->environment->id] ??= Environment::query()->where('public_id', $spec->environment->id)->first());

        if ($spec->sharedServices !== [] && (! $own instanceof Environment || $own->secrets_withheld)) {
            return [];
        }

        foreach (array_unique(array_values($spec->sharedServices)) as $environmentId) {
            $base = $this->bases[$environmentId] ??= Environment::query()->where('public_id', $environmentId)->first();

            if ($base instanceof Environment) {
                $networks[] = ['network' => new NetworkSpec($base->networkId(), $base->networkName(), $base->network_subnet), 'aliases' => []];
            }
        }

        return $networks;
    }

    /**
     * The forward-auth URL this server's Caddy asks: the dashboard's local
     * upstream on the control-plane host, else the configured URL, else the
     * dashboard origin this server's launchroomd talks to. It must match the daemon's grammar (`http(s)://host[:port]/auth/preview`).
     */
    private function authUrl(): ?string
    {
        $upstream = config('launchroom.control_plane.dashboard_upstream');
        $configured = config('launchroom.previews.auth_url');

        $url = match (true) {
            $this->server->is_control_plane && is_string($upstream) && preg_match('/^\d{1,3}(?:\.\d{1,3}){3}:\d{1,5}$/D', $upstream) === 1 => "http://{$upstream}/auth/preview",
            is_string($configured) && $configured !== '' => $configured,
            // The origin this server's launchroomd uses: it only calls
            // forward-auth URLs there (docs/05 §4.1, docs/03 §8).
            default => resolve(DashboardOrigin::class)->forServer($this->server).'/auth/preview',
        };

        return preg_match('#^https?://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::[1-9][0-9]{0,4})?/auth/preview$#D', $url) === 1 ? $url : null;
    }
}
