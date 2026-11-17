<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\NodeState;
use App\Data\Protocol\V1\SystemContainer;
use App\Data\Protocol\V1\SystemSpec;
use App\Data\Protocol\V1\WorkloadSpec;
use App\Data\Servers\NodeStateFacts;
use App\Data\Servers\RenderErrorData;
use App\Enums\DaemonNudge;
use App\Enums\DeploymentStatus;
use App\Enums\ServerRole;
use App\Events\NodeStateInvalidated;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\NodeStateVersion;
use App\Models\Placement;
use App\Models\Server;
use App\Services\Alerts\FallbackWatchdog;
use App\Services\Daemon\DaemonNudger;
use App\Services\Ingress\CertStorage;
use App\Services\Ingress\EdgeIngress;
use App\Services\Instance\DashboardOrigin;
use App\Services\Mesh\MeshTopology;
use App\Services\NodeStates\DashboardRoute;
use App\Services\NodeStates\FeatureGate;
use App\Services\NodeStates\LinkProbePeers;
use App\Services\NodeStates\MeshLinks;
use App\Services\NodeStates\NodeStateContent;
use App\Services\NodeStates\NodeStateProblem;
use App\Services\NodeStates\NodeStateRender;
use App\Services\NodeStates\NodeStateValidator;
use App\Services\Volumes\VolumeRetirements;
use App\Support\CanonicalJson;
use App\Support\Hostname;
use App\Support\Origin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Renders the desired state of one server (docs/03 §5) and stores it as a
 * new version when it differs from the latest one.
 *
 * The version is bumped atomically under a row lock on the server
 * (docs/10 §2.2 rule 5), so concurrent renders never reuse a number.
 *
 * Content: `system` (Caddy on edge servers, BuildKit on build servers) plus
 * the networks, volumes, workloads and routes of its placements
 * (App\Services\NodeStates\NodeStateContent).
 * The control-plane server also gets the dashboard's own route
 * (App\Services\NodeStates\DashboardRoute). Sealed envs are randomized, so
 * "unchanged" is decided on the fingerprint content (env fingerprints instead
 * of ciphertexts).
 * A new version nudges the daemon ("state.changed" on `daemon.{server}`, after
 * commit, docs/03 §4), so it fetches within about a second instead of at its
 * next 30 s poll.
 *
 * Never ships invalid state: every document is checked against the protocol
 * schema and the daemon's cross-reference rules (NodeStateValidator) before
 * it is stored. launchroomd refuses an invalid document whole, which would
 * stall every workload on the server. On failure nothing is published (the
 * previous version stays), `servers.render_error` records why (shown on the
 * server page and in its health checks), the error is logged, and the
 * in-flight deployments the problems belong to are advanced, which fails
 * them with `config_error` (AdvanceDeployment). A valid render clears it.
 */
final readonly class RenderNodeState
{
    public function __construct(
        private NodeStateContent $content,
        private DashboardRoute $dashboardRoute,
        private DaemonNudger $nudger,
        private EdgeIngress $edgeIngress,
        private NodeStateValidator $validator,
        private VolumeRetirements $retirements,
        private MeshTopology $mesh,
        private MeshLinks $meshLinks,
        private CertStorage $certStorage,
        private LinkProbePeers $linkProbePeers,
        private DashboardOrigin $origin,
        private FallbackWatchdog $watchdog,
    ) {}

    public function handle(Server $server): NodeStateRender
    {
        return DB::transaction(function () use ($server): NodeStateRender {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $content = $this->content->for($server);
            $declared = [];

            foreach ($content['volumes'] as $volume) {
                $declared[] = $volume->id;
                $declared[] = $volume->name;
            }

            $state = FeatureGate::downgrade(new NodeState(
                serverId: $server->public_id,
                version: $server->desired_version + 1,
                issuedAt: now()->toImmutable(),
                system: $this->system($server),
                networks: $content['networks'],
                volumes: $content['volumes'],
                workloads: $content['workloads'],
                ambassadors: $content['ambassadors'],
                routes: $this->dashboardRoute->apply($server, $content['routes']),
                meshAllow: $content['meshAllow'],
                deleteVolumes: $this->retirements->deletions($server, $declared),
                ingress: $this->edgeIngress->for($server),
            ), $server);

            $latest = NodeStateVersion::query()
                ->where('server_id', $server->id)
                ->orderByDesc('version')
                ->first();

            $document = ProtocolJson::encode($state);
            $problems = $this->validator->problems($document);

            if ($problems !== []) {
                return $this->refuse($server, $state, $problems, $latest);
            }

            if ($server->render_error !== null) {
                $server->forceFill(['render_error' => null])->save();
            }

            $contentHash = hash('sha256', CanonicalJson::encode($state->fingerprintContent()));

            if ($latest !== null && hash_equals($latest->document_hash, $contentHash)) {
                return new NodeStateRender($latest);
            }

            return new NodeStateRender($this->store($server, $state, $document, $contentHash, $latest));
        });
    }

    private function store(Server $server, NodeState $state, string $document, string $contentHash, ?NodeStateVersion $previous = null): NodeStateVersion
    {
        $server->forceFill(['desired_version' => $state->version])->save();
        $this->nudger->nudge([$server->public_id], DaemonNudge::StateChanged);
        $this->invalidatePeers($server, $state, $previous);

        return NodeStateVersion::query()->create([
            'server_id' => $server->id,
            'version' => $state->version,
            'document' => $document,
            'document_hash' => $contentHash,
            'facts' => NodeStateFacts::of($state),
            'created_at' => now(),
        ]);
    }

    /**
     * Invalid render: keep the previous version, record and log why, and
     * advance the in-flight deployments it belongs to (they fail).
     *
     * @param  list<NodeStateProblem>  $problems
     */
    private function refuse(Server $server, NodeState $state, array $problems, ?NodeStateVersion $latest): NodeStateRender
    {
        $lines = array_map(strval(...), $problems);
        $shown = array_slice($lines, 0, 3);
        $more = count($lines) - count($shown);
        $message = 'Desired state could not be rendered: '.implode('; ', $shown).($more > 0 ? " (and {$more} more)" : '');
        $deployments = $this->attribute($server, $state, $problems);

        $server->forceFill(['render_error' => new RenderErrorData(
            message: mb_substr($message, 0, 2000),
            problems: $lines,
            deployments: array_values(array_filter(Deployment::query()->whereKey($deployments)->orderBy('id')->pluck('public_id')->all(), is_string(...))),
            at: now()->toIso8601String(),
        )])->save();

        Log::error('Refused to publish an invalid NodeState; the previous version stays.', [
            'server' => $server->public_id,
            'version' => $state->version,
            'problems' => $lines,
            'deployments' => $deployments,
        ]);

        foreach ($deployments as $deploymentId) {
            dispatch(new AdvanceDeploymentJob($deploymentId))->afterCommit();
        }

        if (! $latest instanceof NodeStateVersion) {
            // Nothing was ever published: a server without workloads is the
            // safe previous state. It has no content that could be invalid.
            $empty = new NodeState($server->public_id, $state->version, $state->issuedAt, $state->system);
            $document = ProtocolJson::encode($empty);
            $latest = $this->store($server, $empty, $document, hash('sha256', CanonicalJson::encode($empty->fingerprintContent())));
        }

        return new NodeStateRender($latest, $message, $deployments);
    }

    /**
     * In-flight deployments the problems belong to: through the workloads,
     * routes, networks and volumes their paths name; all in-flight
     * deployments on the server when none can be attributed (they could not
     * roll out here anyway).
     *
     * @param  list<NodeStateProblem>  $problems
     * @return list<int>
     */
    private function attribute(Server $server, NodeState $state, array $problems): array
    {
        $publicIds = [];

        foreach ($problems as $problem) {
            $index = $problem->index;

            if ($index === null || ! in_array($problem->section, ['workloads', 'routes', 'networks', 'volumes'], true)) {
                continue;
            }

            $workloads = match ($problem->section) {
                'workloads' => isset($state->workloads[$index]) ? [$state->workloads[$index]] : [],
                'routes' => isset($state->routes[$index]) ? array_filter($state->workloads, static fn (WorkloadSpec $w): bool => in_array($w->id, array_column($state->routes[$index]->upstreams, 'workload'), true)) : [],
                'networks' => isset($state->networks[$index]) ? array_filter($state->workloads, static fn (WorkloadSpec $w): bool => in_array($state->networks[$index]->id, array_column($w->networks, 'network'), true)) : [],
                default => isset($state->volumes[$index]) ? array_filter($state->workloads, static fn (WorkloadSpec $w): bool => in_array($state->volumes[$index]->id, array_column($w->mounts ?? [], 'volume'), true)) : [],
            };

            foreach ($workloads as $workload) {
                $publicIds[$workload->deployment] = true;
            }
        }

        $inFlight = DeploymentStatus::values(DeploymentStatus::inFlight());
        $attributed = $publicIds === [] ? [] : Deployment::query()
            ->where('team_id', $server->team_id)
            ->whereIn('public_id', array_keys($publicIds))
            ->whereIn('status', $inFlight)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($attributed !== []) {
            return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $attributed));
        }

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, Deployment::query()
            ->whereIn('id', Placement::query()->where('server_id', $server->id)->select('deployment_id'))
            ->whereIn('status', $inFlight)
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }

    /**
     * A new version can change what other servers render (docs/05 §3): the
     * providers' allow-lists and published ports, the consumers' ambassadors
     * and the edges' mesh upstreams. Re-render the servers hosting placements
     * in this server's environments, before and after (departures count),
     * plus the team's edges. Unchanged renders invalidate nothing, so this
     * converges.
     */
    private function invalidatePeers(Server $server, NodeState $state, ?NodeStateVersion $previous): void
    {
        $environments = [];
        $networks = [...NodeStateFacts::of($state)->networks, ...$previous->facts->networks ?? []];

        foreach ($networks as $id) {
            $environment = Environment::publicIdFromNetworkId($id);

            if ($environment !== null) {
                $environments[$environment] = true;
            }
        }

        $peers = $this->meshLinks->peers($server, array_keys($environments));

        NodeStateInvalidated::servers($peers);
    }

    private function system(Server $server): SystemSpec
    {
        // The mesh is switched on when a second server joins (docs/05 §2).
        [$forwardTo, $serveOnMesh] = $this->mesh->registry($server);
        // Automatic shared certificate storage: the control plane serves Valkey on its mesh IP (docs/05 §5).
        $certStorageFrom = $this->certStorage->allowFrom($server);
        $tailscale = $this->mesh->tailscale($server);

        return new SystemSpec(
            caddy: $this->container($server->hasRole(ServerRole::Edge), 'caddy'),
            buildkit: $this->container($server->hasRole(ServerRole::Build), 'buildkit'),
            tailscale: $tailscale,
            registryForwardTo: $forwardTo,
            registryServeOnMesh: $serveOnMesh,
            certStorageServeOnMesh: $certStorageFrom !== null,
            certStorageAllowFrom: $certStorageFrom ?? [],
            linkProbePeers: $this->linkProbePeers->for($server, $tailscale->enabled),
            // Where launchroomd should reach the dashboard (docs/05 §4.1); it
            // keeps its configured URL as the fallback.
            controlPlaneUrl: $this->controlPlaneUrl($server),
            // The watchdog and its fallback channel (D90): the control-plane server and one peer.
            watchdog: $this->watchdog->for($server),
        );
    }

    /**
     * The canonical dashboard origin, as long as it is an https origin with a
     * DNS name (launchroomd's grammar). Never for the control-plane server:
     * its launchroomd talks to the dashboard over loopback.
     */
    private function controlPlaneUrl(Server $server): ?string
    {
        if ($server->is_control_plane) {
            return null;
        }

        $canonical = $this->origin->canonical();
        $host = Origin::host($canonical);

        return Origin::isHttps($canonical) && $host !== null && Hostname::isValid($host) ? $canonical : null;
    }

    private function container(bool $enabled, string $name): SystemContainer
    {
        return $enabled
            ? new SystemContainer(true, config()->string("launchroom.daemon.system_images.{$name}"))
            : new SystemContainer(false);
    }
}
