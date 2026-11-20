<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Domains\DnsCheckData;
use App\Data\Moves\DnsSwitchData;
use App\Data\Moves\DnsSwitchEntryData;
use App\Data\Moves\DnsSwitchRecordData;
use App\Data\Moves\DnsSwitchSnapshotData;
use App\Data\Moves\DnsSwitchTunnelData;
use App\Data\Moves\DnsSwitchValueData;
use App\Data\Moves\MoveDnsRecordData;
use App\Enums\DomainKind;
use App\Enums\Domains\DnsCheckStatus;
use App\Models\Domain;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Services\Dns\DnsCheck;
use App\Services\Moves\MovePlanner;
use App\Support\Hostname;

/**
 * The DNS switch after a control-plane move (docs/14 §13.4): which records
 * to point at the new server, how to reach the new dashboard before they
 * do, and live checks of each (DnsCheck, through the pinned public
 * resolver). The records are taken when the hand-over starts and kept on
 * the move (`dns_switch`): the old dashboard shows them after it handed
 * over, when its copy of the new server no longer reports, and the new one
 * has the same list from the restored database.
 */
final readonly class MoveDnsSwitch
{
    /** The new dashboard's local port (compose.yml publishes it on 127.0.0.1 only). */
    private const string LOCAL_URL = 'http://localhost:8000';

    public function __construct(
        private MovePlanner $planner,
        private DnsCheck $dns,
    ) {}

    public function snapshot(ControlPlaneMove $move): ?DnsSwitchSnapshotData
    {
        $source = $move->source_server_id === null ? null : Server::query()->find($move->source_server_id);
        $target = $move->target_server_id === null ? null : Server::query()->find($move->target_server_id);

        if (! $source instanceof Server || ! $target instanceof Server) {
            return null;
        }

        $records = $this->planner->dnsSwitch($source, $target);
        $custom = array_values(array_filter(array_map(static fn (MoveDnsRecordData $record): ?string => $record->kind === 'domain' ? $record->host : null, $records)));
        $owners = $custom === [] ? collect() : Domain::query()
            ->where('kind', DomainKind::Custom->value)
            ->whereIn('hostname', $custom)
            ->with(['serviceInstance.service', 'serviceInstance.environment.project'])
            ->get()
            ->keyBy('hostname');

        return new DnsSwitchSnapshotData(
            takenAt: now()->toIso8601String(),
            addresses: $this->addresses($target),
            oldAddresses: $this->addresses($source),
            records: array_map(static function (MoveDnsRecordData $record) use ($owners): DnsSwitchEntryData {
                $domain = $owners->get($record->host);

                return new DnsSwitchEntryData(
                    $record->kind,
                    $record->host,
                    $record->addresses,
                    $record->remove,
                    $domain instanceof Domain ? ServicesOnServer::label($domain->serviceInstance) : null,
                );
            }, $records),
        );
    }

    /**
     * The switch as the pages show it; live checks with $check.
     */
    public function get(?DnsSwitchSnapshotData $snapshot, ?string $newUrl, bool $check): ?DnsSwitchData
    {
        if (! $snapshot instanceof DnsSwitchSnapshotData) {
            return null;
        }

        $records = [];

        foreach ($snapshot->records as $entry) {
            $records[] = new DnsSwitchRecordData(
                $entry->kind,
                match ($entry->kind) {
                    'dashboard' => 'Dashboard',
                    'apps' => 'Apps (wildcard)',
                    default => 'Custom domain',
                },
                $entry->name,
                DnsSwitchValueData::fromAddresses($entry->addresses),
                $entry->remove,
                $entry->usedBy,
                $check ? $this->check($entry) : null,
            );
        }

        return new DnsSwitchData(
            newUrl: $newUrl,
            addresses: DnsSwitchValueData::fromAddresses($snapshot->addresses),
            oldAddresses: $snapshot->oldAddresses,
            records: $records,
            reachBeforeDns: $this->tunnel($snapshot, $newUrl),
            checked: $check,
            pointed: count(array_filter($records, static fn (DnsSwitchRecordData $record): bool => $record->check?->status === DnsCheckStatus::Ok)),
            total: count($records),
        );
    }

    private function check(DnsSwitchEntryData $entry): ?DnsCheckData
    {
        $name = ltrim($entry->name, '*.');

        if (Hostname::isLocal($name)) {
            return null;
        }

        return $entry->kind === 'apps' ? $this->dns->wildcard($name, $entry->addresses) : $this->dns->hostname($name, $entry->addresses);
    }

    /**
     * An SSH tunnel to the new server's local dashboard port, when the new
     * address is a domain (it only reaches the new server once its record
     * changed); an IP or sslip.io address works right away.
     */
    private function tunnel(DnsSwitchSnapshotData $snapshot, ?string $newUrl): ?DnsSwitchTunnelData
    {
        $host = $newUrl === null ? null : parse_url($newUrl, PHP_URL_HOST);

        if (is_string($host) && (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || str_ends_with($host, '.sslip.io'))) {
            return null;
        }

        if (! array_any($snapshot->records, static fn (DnsSwitchEntryData $entry): bool => $entry->kind === 'dashboard')) {
            return null;
        }

        $ipv4 = array_find($snapshot->addresses, static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
        $address = $ipv4 ?? ($snapshot->addresses[0] ?? null);

        return $address === null ? null : new DnsSwitchTunnelData("ssh -L 8000:127.0.0.1:8000 root@{$address}", self::LOCAL_URL);
    }

    /**
     * @return list<string>
     */
    private function addresses(Server $server): array
    {
        return array_values(array_filter([$server->public_ipv4, $server->public_ipv6], is_string(...)));
    }
}
