<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A canvas edge (docs/06 §3.2). The variables it injects are ordinary
 * reference variables owned by it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property int $from_service_id
 * @property int $to_service_id
 * @property ConnectionMode $mode
 * @property ConnectionStyle $style
 * @property list<string> $injected
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Service $from
 * @property-read Service $to
 */
#[Fillable(['team_id', 'project_id', 'from_service_id', 'to_service_id', 'mode', 'style', 'injected'])]
final class Connection extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'injected' => '[]',
    ];

    public static function publicIdPrefix(): string
    {
        return 'con';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'from_service_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'to_service_id');
    }

    /**
     * @return HasMany<Variable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ConnectionMode::class,
            'style' => ConnectionStyle::class,
            'injected' => 'array',
        ];
    }
}
