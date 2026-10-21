<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Promotions\PromotionSnapshot;
use App\Enums\PromotionKind;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A promotion between two environments, or the creation of one environment
 * as a copy of another (docs/06 §6.3). The snapshots are the bases of the
 * next three-way comparison: `snapshot` for from → to, `target_snapshot` for
 * to → from. They hold fingerprints only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $project_id
 * @property int $from_environment_id
 * @property int $to_environment_id
 * @property PromotionKind $kind
 * @property int|null $source_revision_id
 * @property PromotionSnapshot $snapshot
 * @property PromotionSnapshot $target_snapshot
 * @property list<string> $items
 * @property array<string, array{deployment: string, commitSha: string, gitRef: string|null}> $images
 * @property Carbon|null $images_applied_at
 * @property int|null $actor_id
 * @property Carbon $created_at
 * @property-read Environment $fromEnvironment
 * @property-read Environment $toEnvironment
 * @property-read User|null $actor
 */
#[Fillable(['team_id', 'project_id', 'from_environment_id', 'to_environment_id', 'kind', 'source_revision_id', 'snapshot', 'target_snapshot', 'items', 'images', 'actor_id', 'created_at'])]
#[Hidden(['snapshot', 'target_snapshot'])]
final class EnvironmentPromotion extends Model
{
    use BelongsToTeam, HasPublicId;

    public const ?string UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'items' => '[]',
        'images' => '{}',
    ];

    public static function publicIdPrefix(): string
    {
        return 'prm';
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function fromEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'from_environment_id');
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function toEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'to_environment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The base for comparing $from → $to, from the latest promotion between
     * the two environments in either direction (null: they never met).
     */
    public static function baseFor(Environment $from, Environment $to): ?PromotionSnapshot
    {
        $latest = self::query()
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $pair) => $pair->where('from_environment_id', $from->id)->where('to_environment_id', $to->id))
                ->orWhere(static fn (Builder $pair) => $pair->where('from_environment_id', $to->id)->where('to_environment_id', $from->id)))
            ->latest('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        return $latest->from_environment_id === $from->id ? $latest->snapshot : $latest->target_snapshot;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PromotionKind::class,
            'snapshot' => PromotionSnapshot::class,
            'target_snapshot' => PromotionSnapshot::class,
            'items' => 'array',
            'images' => 'array',
            'images_applied_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
