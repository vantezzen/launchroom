<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A variable row (docs/06 §3, docs/07). `value` is the AEAD ciphertext under
 * the project DEK with associated data `team|project|variable|version`
 * (App\Services\Crypto\ProjectKeyring); it is never serialized.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property int|null $service_id
 * @property int|null $connection_id
 * @property string $key
 * @property string $value
 * @property int $version
 * @property VariableType $type
 * @property VariableExposure|null $exposure_override
 * @property bool $generated
 * @property string|null $generator
 * @property list<string> $references
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Service|null $service
 * @property-read Connection|null $connection
 */
#[Fillable(['team_id', 'project_id', 'service_id', 'connection_id', 'key', 'value', 'version', 'type', 'exposure_override', 'generated', 'generator', 'references', 'created_by', 'updated_by'])]
#[Hidden(['value'])]
final class Variable extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => 1,
        'generated' => false,
        'references' => '[]',
    ];

    public static function publicIdPrefix(): string
    {
        return 'var';
    }

    public function exposure(): VariableExposure
    {
        return VariableExposure::effective($this->key, $this->exposure_override);
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
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return HasMany<VariableTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(VariableTarget::class);
    }

    /**
     * Services that opted into this shared variable (docs/06 §3.1).
     *
     * @return BelongsToMany<Service, $this>
     */
    public function usedBy(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'shared_variable_usages')->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'type' => VariableType::class,
            'exposure_override' => VariableExposure::class,
            'generated' => 'boolean',
            'references' => 'array',
        ];
    }
}
