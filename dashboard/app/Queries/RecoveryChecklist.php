<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Instance\RecoveryChecklistData;
use App\Data\Instance\RecoveryStateData;
use App\Data\Servers\ReplaceableServerData;
use App\Data\Servers\ReplacementPlanItemData;
use App\Data\Servers\ServerReplacementData;
use App\Enums\ServerReplacementStatus;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerReplacement;
use App\Services\Dns\EdgeAddresses;
use App\Services\Instance\RecoveryMode;
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
        private ControlPlaneCutoverChecks $cutover,
        private EdgeAddresses $edges,
    ) {}

    public function get(bool $checkImages): RecoveryChecklistData
    {
        $state = RecoveryMode::state();
        $previous = RecoveryMode::previousControlPlane();
        $previousId = $previous?->id;
        $current = Server::query()
            ->controlPlane()
            ->when($previousId !== null, static fn ($query) => $query->whereKeyNot($previousId))
            ->orderByDesc('id')
            ->first();
        $replacementId = $state?->replacementId;
        $replacement = $replacementId !== null ? ServerReplacement::query()->whereKey($replacementId)->with(['oldServer', 'newServer'])->first() : null;
        $startedAt = $state instanceof RecoveryStateData ? CarbonImmutable::parse($state->startedAt) : null;

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
        $checks = $this->cutover->checks(
            $expected,
            $this->cutover->dns(is_string($dashboardHost) ? $dashboardHost : null, false, $expected, true),
            $this->cutover->servers($current, $startedAt, $previousId === null ? [] : [$previousId]),
            $checkImages ? $this->cutover->images() : null,
        );
        $replaced = ! $previous instanceof Server || $previous->status === ServerStatus::Revoked || $replacement instanceof ServerReplacement;
        $restoring = $replacement instanceof ServerReplacement && $replacement->status === ServerReplacementStatus::Running;

        return new RecoveryChecklistData(
            active: $state instanceof RecoveryStateData,
            startedAt: $startedAt?->toIso8601String(),
            previous: $previous instanceof Server ? ReplaceableServerData::fromServer($previous) : null,
            current: $current instanceof Server ? ReplaceableServerData::fromServer($current) : null,
            plan: $plan,
            replacement: $replacement instanceof ServerReplacement ? ServerReplacementData::fromReplacement($replacement) : null,
            dashboardHost: is_string($dashboardHost) ? $dashboardHost : null,
            checks: $checks,
            openSteps: [...($replaced ? [] : ['replace']), ...$checks->openSteps(), ...($restoring ? ['restores'] : [])],
        );
    }
}
