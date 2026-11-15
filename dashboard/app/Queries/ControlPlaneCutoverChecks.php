<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Moves\CutoverChecksData;
use App\Data\Moves\CutoverDnsRowData;
use App\Data\Moves\CutoverImagesData;
use App\Data\Moves\CutoverMissingImageData;
use App\Data\Moves\CutoverServerData;
use App\Enums\DomainKind;
use App\Enums\Domains\DnsCheckStatus;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Dns\DnsCheck;
use App\Support\Hostname;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The checks after the control plane changed machines, shared by the move
 * checklist (docs/14 §13.4) and the recovery checklist (docs/14 §5.2): DNS
 * points at the new machine, the other servers reconnected, the new
 * registry has every live image.
 */
final readonly class ControlPlaneCutoverChecks
{
    public function __construct(
        private DnsCheck $dns,
        private MissingImages $images,
    ) {}

    /**
     * The checks and their verdicts, one rule for both checklists: DNS is done
     * when every name was checked and points here (nothing to point counts as
     * done), images when the registry was checked and misses none, servers
     * when every other server reconnected.
     *
     * @param  list<string>  $expected
     * @param  list<CutoverDnsRowData>  $dns
     * @param  list<CutoverServerData>  $servers
     */
    public function checks(array $expected, array $dns, array $servers, ?CutoverImagesData $images): CutoverChecksData
    {
        return new CutoverChecksData(
            expectedAddresses: $expected,
            dns: $dns,
            servers: $servers,
            images: $images,
            dnsOk: array_all($dns, static fn (CutoverDnsRowData $row): bool => $row->check?->status === DnsCheckStatus::Ok),
            imagesOk: $images instanceof CutoverImagesData && $images->missing === [],
            serversOk: array_all($servers, static fn (CutoverServerData $server): bool => $server->reconnected),
        );
    }

    /**
     * The dashboard host (left out when local), the apps wildcard and, with
     * $customDomains, the custom domains of live services (at most 50).
     *
     * @param  list<string>  $expected  the edge addresses the names must reach
     * @return list<CutoverDnsRowData>
     */
    public function dns(?string $dashboardHost, bool $customDomains, array $expected, bool $check): array
    {
        $rows = [];

        if ($dashboardHost !== null && $dashboardHost !== '' && ! Hostname::isLocal($dashboardHost)) {
            $rows[] = new CutoverDnsRowData('Dashboard', $dashboardHost, $check ? $this->dns->hostname($dashboardHost, $expected) : null);
        }

        $appsDomain = InstanceSettings::current()->apps_domain;

        if (is_string($appsDomain) && $appsDomain !== '') {
            $rows[] = new CutoverDnsRowData('Apps', '*.'.$appsDomain, $check ? $this->dns->wildcard($appsDomain, $expected) : null);
        }

        $custom = $customDomains
            ? Domain::query()->where('kind', DomainKind::Custom->value)->whereHas('serviceInstance', static fn (Builder $query) => $query->whereNull('removed_at'))->orderBy('hostname')->limit(50)->pluck('hostname')->all()
            : [];

        foreach ($custom as $hostname) {
            if (is_string($hostname)) {
                $rows[] = new CutoverDnsRowData('Domain', $hostname, $check ? $this->dns->hostname($hostname, $expected) : null);
            }
        }

        return $rows;
    }

    /**
     * The team's other approved servers; reconnected once seen after $startedAt.
     *
     * @param  list<int>  $exclude
     * @return list<CutoverServerData>
     */
    public function servers(?Server $current, ?CarbonImmutable $startedAt, array $exclude = []): array
    {
        if (! $current instanceof Server) {
            return [];
        }

        return array_values(Server::query()
            ->where('team_id', $current->team_id)
            ->whereKeyNot([$current->id, ...$exclude])
            ->approved()
            ->orderBy('name')
            ->get()
            ->map(static fn (Server $server): CutoverServerData => new CutoverServerData(
                $server->public_id,
                $server->name,
                $server->status,
                $server->last_seen_at?->toIso8601String(),
                $startedAt instanceof CarbonImmutable && $server->last_seen_at !== null && $server->last_seen_at->gte($startedAt),
            ))
            ->all());
    }

    public function images(): CutoverImagesData
    {
        $scan = $this->images->scan();

        return new CutoverImagesData($scan['checked'], $scan['unknown'], array_map(static fn (Deployment $deployment): CutoverMissingImageData => new CutoverMissingImageData(
            $deployment->serviceInstance->service->name,
            $deployment->public_id,
            substr((string) $deployment->commit_sha, 0, 7),
        ), $scan['missing']));
    }
}
