<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Moves\MoveServerData;
use App\Data\Moves\ServerMoveData;
use App\Enums\Moves\ServerMoveStatus;
use App\Enums\ServerStatus;
use App\Models\Moves\ServerMove;
use App\Models\Server;
use App\Models\User;
use App\Services\Moves\MovePlanner;
use Illuminate\Contracts\Database\Query\Builder;
use Inertia\Inertia;

/**
 * Props of Servers → (server) → Move to another server
 * (resources/js/pages/servers/move.tsx, docs/14 §13.2): the plan for the
 * chosen target (live DNS checks are deferred), the candidates, and the
 * latest move of this server with its services.
 */
final readonly class ServerMovePage
{
    public function __construct(
        private MovePlanner $planner,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Server $server, User $user, ?string $targetId): array
    {
        $server->loadMissing('team');
        $move = ServerMove::query()->where(static fn (Builder $query) => $query->where('source_server_id', $server->id))->latest('id')->first();
        $open = $move instanceof ServerMove && $move->status->isOpen() ? $move : null;

        $candidates = Server::query()
            ->where('team_id', $server->team_id)
            ->whereKeyNot($server->id)
            ->where('status', ServerStatus::Online->value)
            ->whereNull('replaced_by_server_id')
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get();

        $target = $open?->target()->first()
            ?? ($targetId === null ? null : $candidates->firstWhere('public_id', $targetId))
            ?? ($candidates->count() === 1 ? $candidates->first() : null);

        return [
            'server' => MoveServerData::fromServer($server),
            'candidates' => array_values($candidates->map(static fn (Server $candidate): MoveServerData => MoveServerData::fromServer($candidate))->all()),
            'targetId' => $target?->public_id,
            'plan' => $open instanceof ServerMove ? null : $this->planner->plan($server, $target, $server->is_control_plane && $move?->control_plane === true),
            // Live DNS lookups only when asked for (the page loads them after rendering).
            'dns' => Inertia::defer(fn (): array => $target instanceof Server ? $this->planner->plan($server, $target, $move?->control_plane === true, checkDns: true)->dns : []),
            'move' => $move instanceof ServerMove && ($open instanceof ServerMove || $move->status === ServerMoveStatus::Completed || $move->finished_at?->gt(now()->subDay()) === true) ? ServerMoveData::fromMove($move) : null,
            'canEnroll' => $user->can('create', [Server::class, $server->team]),
        ];
    }
}
