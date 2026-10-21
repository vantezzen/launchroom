<?php

declare(strict_types=1);

namespace App\Models\Moves;

use App\Enums\Moves\ServerMoveStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property array{backupFirst?: bool} $options
 * @property array<string, mixed> $plan what the plan said when it started (platforms, DNS records, estimates)
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
     * The only way a move's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(ServerMoveStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to === ServerMoveStatus::Ready) {
            $this->forceFill(['ready_at' => now()]);
        }

        if ($to === ServerMoveStatus::Running) {
            $this->forceFill(['ready_at' => null]);
        }

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
        }

        if ($error !== null) {
            $this->error = mb_substr($error, 0, 2048);
        }

        $this->save();
    }

    public function backupFirst(): bool
    {
        return ($this->options['backupFirst'] ?? false) === true;
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
            'options' => 'array',
            'plan' => 'array',
            'edge_added' => 'boolean',
            'ready_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
