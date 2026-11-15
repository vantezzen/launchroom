<?php

declare(strict_types=1);

namespace App\Models\Moves;

use App\Data\Moves\ServerMoveOptionsData;
use App\Data\Moves\StartedMovePlanData;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\Moves\ServerMoveStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A planned move of everything on a server (the source, still online) to
 * another server of the team (docs/14 §13, D56). While it is open the
 * source drains: the scheduler places nothing new there except services
 * still pinned to it by their volumes.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $source_server_id
 * @property int $target_server_id
 * @property bool $control_plane the workload half of Settings → Move launchroom
 * @property ServerMoveStatus $status
 * @property ServerMoveOptionsData $options
 * @property StartedMovePlanData $plan what the plan said when it started (platforms, DNS records, estimates)
 * @property bool $edge_added the target got the edge role for this move
 * @property string|null $error
 * @property int|null $created_by
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $source
 * @property-read Server $target
 * @property-read User|null $creator
 * @property-read Collection<int, ServerMoveItem> $items
 */
#[Fillable(['team_id', 'source_server_id', 'target_server_id', 'control_plane', 'options', 'plan', 'edge_added', 'created_by'])]
final class ServerMove extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @use HasStatusTransitions<ServerMoveStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
        'options' => '{}',
        'plan' => '{}',
        'control_plane' => false,
        'edge_added' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'smv';
    }

    /**
     * @param  ServerMoveStatus  $from
     * @param  ServerMoveStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to === ServerMoveStatus::Ready) {
            $this->forceFill(['ready_at' => now()]);
        }

        if ($to === ServerMoveStatus::Running) {
            $this->forceFill(['ready_at' => null]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
        }

        if ($note !== null) {
            $this->error = $note;
        }
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function advancing(Builder $query): void
    {
        $query->whereIn('status', ServerMoveStatus::values(ServerMoveStatus::advancing()));
    }

    public function backupFirst(): bool
    {
        return $this->options->backupFirst;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ServerMoveItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ServerMoveItem::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServerMoveStatus::class,
            'control_plane' => 'boolean',
            'options' => ServerMoveOptionsData::class,
            'plan' => StartedMovePlanData::class,
            'edge_added' => 'boolean',
            'ready_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
