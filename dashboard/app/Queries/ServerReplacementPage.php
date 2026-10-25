<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\DeviceData;
use App\Data\Servers\ReplaceableServerData;
use App\Data\Servers\ReplacementPlanItemData;
use App\Data\Servers\ServerReplacementData;
use App\Enums\ServerReplacementStatus;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerReplacement;
use App\Models\User;

/**
 * Props of the "Replace server" wizard (resources/js/pages/servers/replace.tsx).
 */
final readonly class ServerReplacementPage
{
    public function __construct(
        private ServerReplacementPlan $plan,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Server $server, User $user): array
    {
        $server->loadMissing('team');
        $replacement = ServerReplacement::query()->where('old_server_id', $server->id)->with(['oldServer', 'newServer'])->latest('id')->first();
        $replacedBy = $server->loadMissing('replacedBy')->replacedBy?->name;
        $running = $replacement?->status === ServerReplacementStatus::Running;

        $blocked = match (true) {
            $server->status === ServerStatus::Revoked => $server->replaced_at !== null ? "{$server->name} was replaced." : "{$server->name} was revoked, so there is nothing left to replace.",
            $server->status === ServerStatus::PendingApproval => "{$server->name} was never approved.",
            $server->is_control_plane => 'This server runs the dashboard. If it is gone, restore the control plane on a new server with `install.sh --restore`. The dashboard then opens in recovery mode, where you can replace it.',
            $server->status === ServerStatus::Online => "{$server->name} is online. Replace a server once it is unreachable or offline (or revoke it if you retire it on purpose).",
            $running => 'A replacement is in progress.',
            default => null,
        };

        $candidates = Server::query()
            ->where('team_id', $server->team_id)
            ->whereKeyNot($server->id)
            ->where('status', ServerStatus::Online->value)
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get();

        $plan = $server->status === ServerStatus::Revoked ? [] : array_map(static fn (array $entry): ReplacementPlanItemData => new ReplacementPlanItemData(
            $entry['instance']->public_id,
            "{$entry['instance']->service->name} ({$entry['instance']->environment->project->name} / {$entry['instance']->environment->name})",
            $entry['stateful'],
            $entry['restore'] === null ? null : substr($entry['restore']['snapshot'], 0, 8),
            $entry['restore']['takenAt'] ?? null,
            $entry['reason'],
        ), $this->plan->for($server));

        return [
            'server' => ReplaceableServerData::fromServer($server, is_string($replacedBy) ? $replacedBy : null),
            'candidates' => array_values($candidates->map(static fn (Server $candidate): ReplaceableServerData => ReplaceableServerData::fromServer($candidate))->all()),
            'plan' => $plan,
            'replacement' => $replacement instanceof ServerReplacement ? ServerReplacementData::fromReplacement($replacement) : null,
            'blockedReason' => $blocked,
            'canEnroll' => $user->can('create', [Server::class, $server->team]),
            // The device picture of the dead server and each candidate.
            'devices' => $candidates->concat([$server])
                ->mapWithKeys(static fn (Server $s): array => [$s->public_id => DeviceData::fromServer($s)->kind->value])
                ->all(),
        ];
    }
}
