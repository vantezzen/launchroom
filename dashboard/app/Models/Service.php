<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ServiceSourceCast;
use App\Data\Services\ServiceSource;
use App\Enums\ServiceKind;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A service's identity, shared by all environments (docs/06 §1). Settings
 * live on its per-environment ServiceInstance.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property string $slug
 * @property string $name
 * @property ServiceKind $kind
 * @property int|null $parent_service_id
 * @property ServiceSource $source
 * @property string|null $icon
 * @property list<array{key: string, value: string}>|null $connection_variables what a connection to it injects (D65), null: the default naming
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Service|null $parent
 */
#[Fillable(['team_id', 'project_id', 'slug', 'name', 'kind', 'parent_service_id', 'source', 'icon'])]
final class Service extends Model
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'svc';
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
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_service_id');
    }

    /**
     * @return HasMany<ServiceInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(ServiceInstance::class);
    }

    /**
     * @return HasMany<Variable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class);
    }

    /**
     * Repository path segment of built images: lowercase (Docker requires it).
     */
    public function imageRepository(): string
    {
        return strtolower($this->public_id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ServiceKind::class,
            'source' => ServiceSourceCast::class,
            'connection_variables' => 'array',
        ];
    }
}
