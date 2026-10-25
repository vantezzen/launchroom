<?php

declare(strict_types=1);

namespace App\Services\Instance;

use App\Data\Domains\DnsCheckData;
use App\Data\Instance\DashboardProbeData;
use App\Enums\ServerRole;
use App\Models\Server;
use App\Services\Dns\DnsCheck;
use App\Services\Http\DashboardProbe;
use App\Support\Hostname;
use App\Support\Network\ServerAddress;

/**
 * The checks of Settings → Dashboard domain (docs/05 §4.1): the dashboard
 * is served by the control-plane server only (its Caddy proxies to the
 * dashboard's loopback port; other edges have no route to it), so DNS must
 * point at that server's public addresses, not at every edge.
 */
final readonly class DashboardDomainChecks
{
    public function __construct(
        private DnsCheck $dns,
        private DashboardProbe $probe,
    ) {}

    /**
     * The records to create: A/AAAA to the control-plane server.
     *
     * @return list<array{string, string}> [type, address]
     */
    public function records(): array
    {
        $server = $this->controlPlane();
        $records = [];

        $ipv4 = ServerAddress::ipv4($server?->public_ipv4);
        $ipv6 = ServerAddress::ipv6($server?->public_ipv6);

        if ($ipv4 !== null) {
            $records[] = ['A', $ipv4];
        }

        if ($ipv6 !== null) {
            $records[] = ['AAAA', $ipv6];
        }

        return $records;
    }

    /**
     * Whether the control-plane Caddy routes the dashboard at all (the
     * production compose file sets the upstream; dev and tests don't).
     */
    public function routable(): bool
    {
        $server = $this->controlPlane();
        $upstream = config('launchroom.control_plane.dashboard_upstream');

        return $server instanceof Server && $server->hasRole(ServerRole::Edge) && is_string($upstream) && $upstream !== '';
    }

    public function dns(string $hostname): DnsCheckData
    {
        if (Hostname::isLocal($hostname)) {
            return new DnsCheckData(
                status: 'ok',
                hostname: $hostname,
                addresses: [],
                expected: [],
                cname: null,
                message: 'Names under .localhost always point at this machine, so no DNS record is needed.',
                checkedAt: now()->toIso8601ZuluString(),
            );
        }

        return $this->dns->hostname($hostname, array_map(static fn (array $record): string => $record[1], $this->records()));
    }

    /**
     * @param  list<string>|null  $addresses  where DNS points (only ours are
     *                                        tested); null when the name reaches this server through a proxy
     *                                        (DNS shows the proxy's addresses): the test request then goes to
     *                                        this server's own addresses directly
     */
    public function probe(string $hostname, ?array $addresses): DashboardProbeData
    {
        $own = array_map(static fn (array $record): string => $record[1], $this->records());

        return $this->probe->check($hostname, $addresses === null ? $own : array_values(array_intersect($own, $addresses)));
    }

    private function controlPlane(): ?Server
    {
        return Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->orderBy('id')->first();
    }
}
