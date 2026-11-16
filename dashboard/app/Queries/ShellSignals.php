<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\FleetStatusData;
use App\Data\Servers\HealthCheckData;
use App\Enums\Alerts\AlertStatus;
use App\Enums\HealthCheckState;
use App\Enums\ServerStatus;
use App\Enums\StatusTone;
use App\Models\Alerts\Alert;
use App\Models\Security\SecurityFinding;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * What the app shell says on every page (docs/08 §1): the Activity count
 * (open alerts), the Security badge (vulnerabilities that need action,
 * counted per advisory group like the Security page's hero) and the
 * servers' line under the team name. Each one is authorized for the
 * viewer and counts the current team only (the caller passes the user's
 * current team, read through their memberships).
 */
final readonly class ShellSignals
{
    public function __construct(private ServerHealth $health) {}

    /** Firing alerts of the current team (docs/15 §3). */
    public function openAlerts(?User $user, ?Team $team): int
    {
        if (! $user instanceof User || ! $team instanceof Team || ! Gate::forUser($user)->allows('viewAny', [Alert::class, $team])) {
            return 0;
        }

        return Alert::query()
            ->where('team_id', $team->id)
            ->where('status', AlertStatus::Firing->value)
            ->count();
    }

    /**
     * "Action needed" vulnerabilities of the current team, one per advisory
     * group however many services carry it (docs/22 §9.1).
     */
    public function securityActionNeeded(?User $user, ?Team $team): int
    {
        if (! $user instanceof User || ! $team instanceof Team || ! Gate::forUser($user)->allows('viewAny', [SecurityFinding::class, $team])) {
            return 0;
        }

        return SecurityFinding::query()
            ->where('team_id', $team->id)
            ->actionNeeded()
            ->distinct()
            ->count('group_key');
    }

    /**
     * The worst thing about the team's servers, or how many are online
     * when nothing is wrong. Null for people who can't see servers.
     */
    public function fleet(?User $user, ?Team $team): ?FleetStatusData
    {
        if (! $user instanceof User || ! $team instanceof Team || ! Gate::forUser($user)->allows('viewAny', [Server::class, $team])) {
            return null;
        }

        $servers = Server::query()
            ->where('team_id', $team->id)
            ->notRevoked()
            ->orderBy('name')
            ->get();
        $total = $servers->count();
        $online = $servers->filter(static fn (Server $server): bool => $server->status === ServerStatus::Online)->count();
        $down = $servers->filter(static fn (Server $server): bool => $server->status->isDown())->count();
        $waiting = $servers->filter(static fn (Server $server): bool => $server->status === ServerStatus::PendingApproval)->count();
        $fleet = static fn (StatusTone $state, string $label): FleetStatusData => new FleetStatusData($total, $online, $state, $label);

        if ($total === 0) {
            return $fleet(StatusTone::Idle, 'No servers yet');
        }

        if ($down > 0) {
            return $fleet(StatusTone::Failed, $this->servers($down).' down');
        }

        $full = $servers->filter(fn (Server $server): bool => $server->status === ServerStatus::Online && $this->diskAlmostFull($server))->values();

        if ($full->isNotEmpty()) {
            return $fleet(StatusTone::Warning, $full->count() === 1 ? "Disk almost full on {$full->firstOrFail()->name}" : "Disk almost full on {$full->count()} servers");
        }

        if ($waiting > 0) {
            return $fleet(StatusTone::Staged, $this->servers($waiting).' waiting for approval');
        }

        return $fleet(StatusTone::Active, $this->servers($online).' online');
    }

    private function diskAlmostFull(Server $server): bool
    {
        return array_any($this->health->evaluate($server)->checks, fn (HealthCheckData $check): bool => $check->key === 'disk' && in_array($check->state, [HealthCheckState::Warning, HealthCheckState::Critical], true));
    }

    private function servers(int $count): string
    {
        return $count === 1 ? '1 server' : "{$count} servers";
    }
}
