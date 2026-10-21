<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\PublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives a model a prefixed ULID `public_id` (docs/07-data-model.md) that is
 * generated on create and used as the route key. Internal bigint IDs never
 * leave the dashboard.
 *
 * @phpstan-require-extends Model
 */
trait HasPublicId
{
    /**
     * The short type prefix for this model's public IDs, e.g. `prj`.
     */
    abstract public static function publicIdPrefix(): string;

    public static function bootHasPublicId(): void
    {
        static::creating(static function (Model $model): void {
            if (! is_string($model->getAttribute('public_id')) || $model->getAttribute('public_id') === '') {
                $model->setAttribute('public_id', PublicId::generate(static::publicIdPrefix()));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Reject malformed IDs before they reach the database (404 instead of a
     * pointless query; keeps junk out of query logs).
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (($field === null || $field === 'public_id')
            && (! is_string($value) || ! PublicId::isValid($value, static::publicIdPrefix()))) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
