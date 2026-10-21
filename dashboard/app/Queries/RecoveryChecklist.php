<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\ReplaceableServerData;
use App\Data\Servers\ReplacementPlanItemData;
use App\Data\Servers\ServerReplacementData;
use App\Enums\ServerStatus;
use App\Models\Deployment;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerReplacement;
use App\Services\Dns\DnsCheck;
use App\Services\Dns\EdgeAddresses;
use App\Services\Instance\RecoveryMode;
use App\Support\Hostname;
use Carbon\CarbonImmutable;

/**
 * The recovery-mode checklist (docs/14 §5.2 step 3, resources/js/pages/recovery.tsx):
 *
 * a. replace the previous control-plane server with this one (roles and
 *    labels move, old keys revoked) — the same flow as "Replace server";
 * b. DNS for the dashboard and the apps domain points at this server;
 * c./d. workloads re-placed and stateful services restored (progress of a.);
 * e. images missing from the registry rebuilt from their recorded commits;
 * plus whether the other servers reconnected (same signing key and URL).
 */
final readonly class RecoveryChecklist
{
    public function __construct(
        private ServerReplacementPlan $plan,
        private DnsCheck $dns,
        private EdgeAddresses $edges,
        private MissingImages $images,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(bool $checkImages): array
    {
        $state = RecoveryMode::state();
        $previous = RecoveryMode::previousControlPlane();
        $previousId = $previous?->id;
        $current = Server::query()
            ->where('is_control_plane', true)
            ->where('status', '!=', ServerStatus::Revoked->value)
            ->when($previousId !== null, static fn ($query) => $query->whereKeyNot($previousId))
            ->orderByDesc('id')
            ->first();
        $replacementId = $state['replacementId'] ?? null;
        $replacement = is_int($replacementId) ? ServerReplacement::query()->whereKey($replacementId)->with(['oldServer', 'newServer'])->first() : null;
        $startedAt = $state === null ? null : CarbonImmutable::parse($state['startedAt']);

        $plan = $previous instanceof Server && $previous->status !== ServerStatus::Revoked ? array_map(static fn (array $entry): ReplacementPlanItemData => new ReplacementPlanItemData(
            $entry['instance']->public_id,
            "{$entry['instance']->service->name} ({$entry['instance']->environment->project->name} / {$entry['instance']->environment->name})",
            $entry['stateful'],
            $entry['restore'] === null ? null : substr($entry['restore']['snapshot'], 0, 8),
            $entry['restore']['takenAt'] ?? null,
            $entry['reason'],
        ), $this->plan->for($previous)) : [];

        $expected = $current instanceof Server ? $this->edges->addresses($current->team_id) : [];
        $dashboardHost = parse_url(config()->string('app.url'), PHP_URL_HOST);
        $appsDomain = InstanceSettings::current()->apps_domain;

        $others = $current instanceof Server ? Server::query()
            ->where('team_id', $current->team_id)
            ->whereKeyNot($current->id)
            ->when($previousId !== null, static fn ($query) => $query->whereKeyNot($previousId))
            ->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value, ServerStatus::Offline->value])
            ->orderBy('name')
            ->get()
            ->all() : [];

        $images = $checkImages ? $this->images->scan() : null;

        return [
            'active' => $state !== null,
            'startedAt' => $startedAt?->toIso8601String(),
            'previous' => $previous instanceof Server ? ReplaceableServerData::fromServer($previous) : null,
            'current' => $current instanceof Server ? ReplaceableServerData::fromServer($current) : null,
            'plan' => $plan,
            'replacement' => $replacement instanceof ServerReplacement ? ServerReplacementData::fromReplacement($replacement) : null,
            'dns' => array_values(array_filter([
                is_string($dashboardHost) && $dashboardHost !== '' && ! Hostname::isLocal($dashboardHost) ? ['label' => 'Dashboard', 'check' => $this->dns->hostname($dashboardHost, $expected)] : null,
                is_string($appsDomain) && $appsDomain !== '' ? ['label' => 'Apps (*.'.$appsDomain.')', 'check' => $this->dns->wildcard($appsDomain, $expected)] : null,
            ])),
            'dashboardHost' => is_string($dashboardHost) ? $dashboardHost : null,
            'expectedAddresses' => $expected,
            'servers' => array_values(array_map(static fn (Server $server): array => [
                'id' => $server->public_id,
                'name' => $server->name,
                'status' => $server->status->value,
                'lastSeenAt' => $server->last_seen_at?->toIso8601String(),
                'reconnected' => $startedAt instanceof CarbonImmutable && $server->last_seen_at !== null && $server->last_seen_at->gte($startedAt),
            ], $others)),
            'images' => $images === null ? null : [
                'checked' => $images['checked'],
                'unknown' => $images['unknown'],
                'missing' => array_map(static fn (Deployment $deployment): array => [
                    'service' => $deployment->serviceInstance->service->name,
                    'deployment' => $deployment->public_id,
                    'commit' => substr((string) $deployment->commit_sha, 0, 7),
                ], $images['missing']),
            ],
        ];
    }
}
