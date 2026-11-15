<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Servers\ReplacedServiceData;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\ServerReplacementStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\DataCollection;

/**
 * One "Replace server" run (docs/14 §5.3, §9.14): the dead server's roles
 * and labels moved to the replacement, its stateful services restored there
 * from their latest backups, its other workloads re-placed, and the dead
 * server revoked (it may only fetch its empty decommission state).
 *
 * `services` tracks progress per service instance ({@see ReplacedServiceData}).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $old_server_id
 * @property int $new_server_id
 * @property ServerReplacementStatus $status
 * @property DataCollection<int, ReplacedServiceData> $services
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

    /** @use HasStatusTransitions<ServerReplacementStatus> */
    use HasStatusTransitions;

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

    /**
     * @param  ServerReplacementStatus  $from
     * @param  ServerReplacementStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        $this->error = $note;
        $this->forceFill(['finished_at' => now()]);
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
            'services' => DataCollection::class.':'.ReplacedServiceData::class.',default',
            'recovery' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }
}
