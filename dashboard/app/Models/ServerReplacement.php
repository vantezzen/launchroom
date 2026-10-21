<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServerReplacementStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One "Replace server" run (docs/14 §5.3, §9.14): the dead server's roles
 * and labels moved to the replacement, its stateful services restored there
 * from their latest backups, its other workloads re-placed, and the dead
 * server revoked (it may only fetch its empty decommission state).
 *
 * `services` tracks progress per service instance:
 * {instance, service, name, kind: stateful|stateless, restore: {snapshot,
 * takenAt}|null, restoreRuns (rsr_…), deployment (dep_…), status: pending |
 * deploying | restoring | restored | done | running | failed | gone, message}.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $old_server_id
 * @property int $new_server_id
 * @property ServerReplacementStatus $status
 * @property list<array{instance: string, service: string, name: string, kind: string, restore: array{snapshot: string, takenAt: string|null}|null, restoreRuns: list<string>, deployment: string|null, status: string, message: string|null}> $services
 * @property bool $recovery
 * @property string|null $error
 * @property int|null $created_by
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $oldServer
 * @property-read Server $newServer
 */
#[Fillable(['team_id', 'old_server_id', 'new_server_id', 'services', 'recovery', 'created_by'])]
final class ServerReplacement extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
        'services' => '[]',
        'recovery' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'srp';
    }

    public function transitionTo(ServerReplacementStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->error = $error === null ? null : mb_substr($error, 0, 2048);
        $this->forceFill(['finished_at' => now()]);
        $this->save();
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function oldServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'old_server_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function newServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'new_server_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServerReplacementStatus::class,
            'services' => 'array',
            'recovery' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }
}
