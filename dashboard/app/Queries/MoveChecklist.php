<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DomainKind;
use App\Enums\ServerStatus;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Dns\DnsCheck;
use App\Services\Dns\EdgeAddresses;
use App\Services\Instance\DashboardOrigin;
use App\Support\Hostname;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The new dashboard's checklist after a control-plane move (docs/14 §13.4,
 * Settings → Move launchroom): DNS of the dashboard, the apps domain and
 * custom domains now points here; images the new registry doesn't have
 * yet; whether other servers reconnected; the old machine, which can be
 * deleted; and a new recovery kit when the address changed.
 */
final readonly class MoveChecklist
{
    public function __construct(
        private DnsCheck $dns,
        private EdgeAddresses $edges,
        private MissingImages $images,
        private DashboardOrigin $origin,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(bool $check): array
    {
        $settings = InstanceSettings::current();
        $state = $settings->move_checklist ?? [];
        $startedAt = is_string($state['startedAt'] ?? null) ? CarbonImmutable::parse($state['startedAt']) : null;
        $previous = is_int($state['previousServerId'] ?? null) ? Server::query()->find($state['previousServerId']) : null;
        $current = is_int($state['newServerId'] ?? null) ? Server::query()->find($state['newServerId']) : null;
        $expected = $this->edges->addresses($current?->team_id);
        $host = $this->origin->canonicalHost();
        $records = [];

        if (is_string($host) && ! Hostname::isLocal($host)) {
            $records[] = ['label' => 'Dashboard', 'host' => $host, 'check' => $check ? $this->dns->hostname($host, $expected) : null];
        }

        if (is_string($settings->apps_domain) && $settings->apps_domain !== '') {
            $records[] = ['label' => 'Apps', 'host' => '*.'.$settings->apps_domain, 'check' => $check ? $this->dns->wildcard($settings->apps_domain, $expected) : null];
        }

        $custom = Domain::query()->where('kind', DomainKind::Custom->value)->whereHas('serviceInstance', static fn (Builder $query) => $query->whereNull('removed_at'))->orderBy('hostname')->limit(50)->pluck('hostname')->all();

        foreach ($custom as $hostname) {
            if (is_string($hostname)) {
                $records[] = ['label' => 'Domain', 'host' => $hostname, 'check' => $check ? $this->dns->hostname($hostname, $expected) : null];
            }
        }

        $others = $current instanceof Server ? Server::query()
            ->where('team_id', $current->team_id)
            ->whereKeyNot($current->id)
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->orderBy('name')
            ->get()
            ->all() : [];

        $images = $check ? $this->images->scan() : null;

        return [
            'startedAt' => $startedAt?->toIso8601String(),
            'previousUrl' => is_string($state['previousUrl'] ?? null) ? $state['previousUrl'] : null,
            'url' => $this->origin->canonical(),
            'previous' => $previous instanceof Server ? ['name' => $previous->name, 'addresses' => array_values(array_filter([$previous->public_ipv4, $previous->public_ipv6]))] : null,
            'current' => $current instanceof Server ? ['name' => $current->name, 'addresses' => array_values(array_filter([$current->public_ipv4, $current->public_ipv6]))] : null,
            'expectedAddresses' => $expected,
            'dns' => $records,
            'servers' => array_values(array_map(static fn (Server $server): array => [
                'id' => $server->public_id,
                'name' => $server->name,
                'status' => $server->status->value,
                'reconnected' => $startedAt instanceof CarbonImmutable && $server->last_seen_at !== null && $server->last_seen_at->gte($startedAt),
            ], $others)),
            'images' => $images === null ? null : [
                'checked' => $images['checked'],
                'missing' => array_map(static fn (Deployment $deployment): array => [
                    'service' => $deployment->serviceInstance->service->name,
                    'deployment' => $deployment->public_id,
                    'commit' => substr((string) $deployment->commit_sha, 0, 7),
                ], $images['missing']),
            ],
            'needsRecoveryKit' => $settings->recovery_passphrase_set_at !== null && $settings->recovery_kit_confirmed_at === null,
        ];
    }
}
