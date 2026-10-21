<?php

declare(strict_types=1);

namespace App\Models\Updates;

use App\Enums\Updates\FleetUpdateStatus;
use App\Models\Concerns\HasPublicId;
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

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
    ];

    public static function publicIdPrefix(): string
    {
        return 'flu';
    }

    public function transitionTo(FleetUpdateStatus $to, ?string $error = null): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now()]);
            $this->error = $error === null ? null : mb_substr($error, 0, 2048);
        }

        $this->save();
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
}
