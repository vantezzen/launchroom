<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Projects\ProjectSettingsData;
use App\Enums\EnvironmentKind;
use App\Enums\ProjectIconMode;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A canvas of services (docs/06 §1, docs/07 "Projects").
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property ProjectSettingsData $settings
 * @property int $color_slot slot 0–7 of the categorical data palette, kept for the project's lifetime (docs/design/direction.md §8.4)
 * @property ProjectIconMode $icon_mode where the icon comes from (docs/08 §13)
 * @property string|null $icon_path generated path on the private disk (ProjectIconStore)
 * @property string|null $icon_type png|webp|svg
 * @property string|null $icon_version content hash, the icon URL's cache buster
 * @property string|null $icon_source the URL a fetched favicon came from (display only)
 * @property Carbon|null $icon_checked_at last automatic lookup
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'name', 'slug', 'settings'])]
final class Project extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'settings' => '{}',
        'color_slot' => 0,
        'icon_mode' => 'automatic',
    ];

    /** Slots of the categorical data palette (direction.md §8.4). */
    public const int COLOR_SLOTS = 8;

    public static function publicIdPrefix(): string
    {
        return 'prj';
    }

    /**
     * The palette slot for a new project of a team: the least-used one,
     * lowest first on ties, so the first eight projects get distinct colours
     * and a deleted project's colour is reused before any doubles up.
     */
    public static function nextColorSlot(int $teamId): int
    {
        /** @var array<int, int> $used */
        $used = self::query()
            ->where('team_id', $teamId)
            ->groupBy('color_slot')
            ->selectRaw('color_slot, count(*) as projects')
            ->pluck('projects', 'color_slot')
            ->map(static fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
            ->all();

        $best = 0;

        for ($slot = 1; $slot < self::COLOR_SLOTS; $slot++) {
            if (($used[$slot] ?? 0) < ($used[$best] ?? 0)) {
                $best = $slot;
            }
        }

        return $best;
    }

    /**
     * The icon's URL (authorized route, cache-busted by content), or null
     * when the project shows its monogram.
     */
    public function iconUrl(): ?string
    {
        return $this->icon_path !== null && $this->icon_version !== null
            ? route('projects.icon', ['project' => $this->public_id, 'v' => $this->icon_version], false)
            : null;
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * @return HasOne<Environment, $this>
     */
    public function production(): HasOne
    {
        return $this->hasOne(Environment::class)->where('kind', EnvironmentKind::Production->value);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<Variable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }

    /**
     * @return HasMany<Connection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /**
     * @return HasMany<ProjectKey, $this>
     */
    public function keys(): HasMany
    {
        return $this->hasMany(ProjectKey::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => ProjectSettingsData::class,
            'color_slot' => 'integer',
            'icon_mode' => ProjectIconMode::class,
            'icon_checked_at' => 'datetime',
        ];
    }
}
