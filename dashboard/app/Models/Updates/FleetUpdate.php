<?php

declare(strict_types=1);

namespace App\Models\Updates;

use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\Updates\FleetUpdateStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A rolling launchroomd update across servers (docs/11 §4): one self_update
 * task at a time, servers with the edge role last, stopped at the first failure.
 *
 * @property int $id
 * @property string $public_id
 * @property string $to_version
 * @property string $daemon_image
 * @property FleetUpdateStatus $status
 * @property int|null $actor_id
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, FleetUpdateStep> $steps
 * @property-read User|null $actor
 */
#[Fillable(['to_version', 'daemon_image', 'actor_id'])]
final class FleetUpdate extends Model
{
    use HasPublicId;

    /** @use HasStatusTransitions<FleetUpdateStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
    ];

    public static function publicIdPrefix(): string
    {
        return 'flu';
    }

    /**
     * @param  FleetUpdateStatus  $from
     * @param  FleetUpdateStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
            $this->error = $note;
        }
    }

    /**
     * @return HasMany<FleetUpdateStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(FleetUpdateStep::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FleetUpdateStatus::class,
            'finished_at' => 'datetime',
        ];
    }

    /** Recorded by the system: updates finish in the background (docs/09 R26). */
    public function finishedAuditEntry(): AuditEntryData
    {
        return new AuditEntryData(
            action: AuditAction::FleetUpdateFinished,
            actor: AuditActor::system(),
            subjectType: 'fleet_update',
            subjectId: $this->public_id,
            meta: ['to' => $this->to_version, 'status' => $this->status->value],
        );
    }
}
