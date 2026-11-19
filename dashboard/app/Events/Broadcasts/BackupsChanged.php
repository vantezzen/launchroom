<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Data\Broadcasts\BackupsChangedData;
use App\Models\Team;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A team's backups changed (a run was created, moved on, logged progress or
 * finished; a destination was tested, added or removed). The Backups page
 * refetches its props instead of polling. A nudge only: no data.
 */
final readonly class BackupsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $teamId,
        public string $scope = 'team',
    ) {}

    /**
     * $teamId null: launchroom's own backups, nudged to every team (instance
     * owners see them on whichever team they look at).
     */
    public static function about(?int $teamId): void
    {
        if ($teamId !== null) {
            $team = Team::query()->whereKey($teamId)->value('public_id');

            if (is_string($team)) {
                event(new self($team));
            }

            return;
        }

        foreach (Team::query()->pluck('public_id') as $team) {
            if (is_string($team)) {
                event(new self($team, 'instance'));
            }
        }
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [Channel::TeamBackups->private($this->teamId)];
    }

    public function broadcastAs(): string
    {
        return BroadcastEvent::BackupsChanged->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new BackupsChangedData($this->teamId, $this->scope)->toArray();
    }
}
