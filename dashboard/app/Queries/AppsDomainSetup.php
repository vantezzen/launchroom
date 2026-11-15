<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Domains\AppsDomainSetupData;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Dns\EdgeAddresses;
use App\Support\GeneratedHostname;
use App\Support\Network\ServerAddress;

/**
 * The platform domain picker's data for Settings → Network (docs/05 §5).
 * Without an edge address yet, the record points at the dashboard's own
 * server, like the address step of Get started.
 */
final readonly class AppsDomainSetup
{
    public function __construct(
        private EdgeAddresses $edges,
    ) {}

    public function get(): AppsDomainSetupData
    {
        $settings = InstanceSettings::current();
        $server = Server::currentControlPlane();
        $ipv4 = ServerAddress::ipv4($server?->public_ipv4);
        $own = $ipv4 ?? ServerAddress::ipv6($server?->public_ipv6);
        $addresses = $this->edges->addresses(null);

        return new AppsDomainSetupData(
            domain: $settings->apps_domain,
            addresses: $addresses !== [] ? $addresses : ($own === null ? [] : [$own]),
            freeWildcard: $ipv4 === null ? null : GeneratedHostname::sslipWildcard($ipv4),
            dashboardHost: (string) parse_url(config()->string('app.url'), PHP_URL_HOST),
            verified: $settings->onboarding?->verified($settings->apps_domain),
        );
    }
}
