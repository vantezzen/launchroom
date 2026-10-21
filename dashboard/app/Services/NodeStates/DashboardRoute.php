<?php

declare(strict_types=1);

namespace App\Services\NodeStates;

use App\Data\Protocol\V1\RouteSpec;
use App\Enums\ClientCertificateMode;
use App\Enums\DaemonFeature;
use App\Enums\ServerRole;
use App\Models\Server;
use App\Services\Daemon\ClientCertificateAuthority;
use App\Services\Daemon\DaemonEdgeSecret;
use App\Services\Instance\DashboardOrigin;
use App\Support\Crypto\IngressSecretSealer;
use App\Support\Hostname;

/**
 * The dashboard's own "system routes" (docs/05 §4, §4.1, docs/11 §2): on the
 * control-plane server, the daemon's Caddy routes the dashboard's hosts to
 * the dashboard container's loopback port (`launchroom.control_plane.dashboard_upstream`,
 * e.g. 127.0.0.1:8000). They are ordinary routes with an address upstream,
 * so the daemon needs nothing new. The hosts belong to the dashboard: an app
 * domain claiming one loses it here.
 *
 * Hosts: the canonical origin's, every earlier origin's that still answers
 * (redirects, launchroomd's fallback; DashboardOrigin::legacy()) and a new
 * domain while Settings → Dashboard domain verifies it (only once its DNS
 * points here, so Caddy's first ACME attempt can succeed). `*.localhost`
 * hosts use Caddy's internal CA in a second route (one TLS mode per route).
 *
 * Off when no upstream is configured (dev, tests); IP hosts are skipped (no
 * certificate for them).
 *
 * Client certificates (mTLS, D50, docs/03 §4.3): while the instance checks
 * them, both system routes carry `clientAuth` (mode, the trusted CAs, the
 * edge secret sealed to this server), so every origin, old and new, asks
 * for and verifies them. Only for a launchroomd that announces
 * `clientCertificates`.
 */
final readonly class DashboardRoute
{
    /** Fixed id (the ULID part spells "DASH"); app routes use domain ULIDs. */
    public const string ID = 'rt_0000000000000000000000DASH';

    /** The `*.localhost` hosts (internal CA). */
    public const string LOCAL_ID = 'rt_0000000000000000000001DASH';

    private const string UPSTREAM_PATTERN = '/^(?:\d{1,3}(?:\.\d{1,3}){3}|\[[0-9a-f:]+\]):\d{1,5}$/Di';

    public function __construct(
        private DashboardOrigin $origin,
        private ClientCertificateAuthority $authority,
        private DaemonEdgeSecret $edgeSecret,
    ) {}

    /**
     * The hosts the control-plane Caddy routes to the dashboard.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $candidates = [
            $this->origin->canonicalHost(),
            ...array_map(static fn (array $legacy): string => $legacy['host'], $this->origin->legacy()),
            $this->origin->pendingHost(),
        ];

        $hosts = [];

        foreach ($candidates as $host) {
            if (is_string($host) && Hostname::isValid($host = strtolower($host)) && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * @param  list<RouteSpec>  $routes
     * @return list<RouteSpec>
     */
    public function apply(Server $server, array $routes): array
    {
        $upstream = config('launchroom.control_plane.dashboard_upstream');

        if (! $server->is_control_plane || ! $server->hasRole(ServerRole::Edge)
            || ! is_string($upstream) || preg_match(self::UPSTREAM_PATTERN, $upstream) !== 1) {
            return $routes;
        }

        $hosts = $this->hosts();

        if ($hosts === []) {
            return $routes;
        }

        $others = [];

        foreach ($routes as $route) {
            $kept = array_values(array_diff($route->hosts, $hosts));

            if ($kept === []) {
                continue;
            }

            $others[] = $kept === $route->hosts ? $route : new RouteSpec(
                $route->id,
                $kept,
                $route->tls,
                $route->upstreams,
                in_array($route->redirectWwwTo, $hosts, true) ? null : $route->redirectWwwTo,
                $route->headers,
                $route->protection,
                $route->loadBalancing,
            );
        }

        $public = array_values(array_filter($hosts, static fn (string $host): bool => ! Hostname::isLocal($host)));
        $local = array_values(array_filter($hosts, Hostname::isLocal(...)));
        $system = [];

        $clientAuth = $this->clientAuth($server);

        if ($public !== []) {
            $system[] = new RouteSpec(self::ID, $public, 'acme', [['address' => $upstream]], clientAuth: $clientAuth);
        }

        if ($local !== []) {
            $system[] = new RouteSpec(self::LOCAL_ID, $local, 'internal', [['address' => $upstream]], clientAuth: $clientAuth);
        }

        return [...$system, ...$others];
    }

    /**
     * @return array{mode: string, caCertificates: list<string>, edgeSecret: array{sealed: string, fingerprint: string}}|null
     */
    private function clientAuth(Server $server): ?array
    {
        $mode = ClientCertificateMode::current();

        if (! $mode->checks() || ! $server->supportsDaemonFeature(DaemonFeature::ClientCertificates)) {
            return null;
        }

        $secret = $this->edgeSecret->current();

        return [
            'mode' => $mode->value,
            'caCertificates' => $this->authority->trusted(),
            'edgeSecret' => [
                'sealed' => IngressSecretSealer::seal($server->sealing_public_key, $server->public_id, IngressSecretSealer::CLIENT_AUTH, ['secret' => $secret]),
                // Stable across renders (sealing is randomized): the secret bound to this server's key.
                'fingerprint' => hash_hmac('sha256', $server->public_id.'|'.$server->sealing_public_key, $secret),
            ],
        ];
    }
}
