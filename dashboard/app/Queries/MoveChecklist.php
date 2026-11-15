<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Moves\CutoverMachineData;
use App\Data\Moves\MoveChecklistData;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Dns\EdgeAddresses;
use App\Services\Instance\DashboardOrigin;
use Carbon\CarbonImmutable;

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
        private ControlPlaneCutoverChecks $cutover,
        private EdgeAddresses $edges,
        private DashboardOrigin $origin,
    ) {}

    public function get(bool $check): MoveChecklistData
    {
        $settings = InstanceSettings::current();
        $state = $settings->move_checklist;
        $startedAt = $state !== null ? CarbonImmutable::parse($state->startedAt) : null;
        $previous = $state?->previousServerId !== null ? Server::query()->find($state->previousServerId) : null;
        $current = $state?->newServerId !== null ? Server::query()->find($state->newServerId) : null;
        $expected = $this->edges->addresses($current?->team_id);
        $host = $this->origin->canonicalHost();
        $records = $this->cutover->dns(is_string($host) ? $host : null, true, $expected, $check);
        $images = $check ? $this->cutover->images() : null;
        $checks = $this->cutover->checks($expected, $records, $this->cutover->servers($current, $startedAt), $images);
        $needsRecoveryKit = $settings->recovery_passphrase_set_at !== null && $settings->recovery_kit_confirmed_at === null;

        return new MoveChecklistData(
            startedAt: $startedAt?->toIso8601String(),
            previousUrl: $state?->previousUrl,
            url: $this->origin->canonical(),
            previous: $previous instanceof Server ? CutoverMachineData::fromServer($previous) : null,
            current: $current instanceof Server ? CutoverMachineData::fromServer($current) : null,
            checks: $checks,
            needsRecoveryKit: $needsRecoveryKit,
            openSteps: [...$checks->openSteps(), ...($needsRecoveryKit ? ['recovery-kit'] : [])],
        );
    }
}
