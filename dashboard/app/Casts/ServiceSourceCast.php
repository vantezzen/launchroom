<?php

declare(strict_types=1);

namespace App\Casts;

use App\Data\Services\ServiceSource;
use App\Data\Services\ServiceSources;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<ServiceSource, ServiceSource>
 */
final class ServiceSourceCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ServiceSource
    {
        if (! is_string($value)) {
            return null;
        }

        $data = json_decode($value, true, 16, JSON_THROW_ON_ERROR);

        return is_array($data) ? ServiceSources::fromArray($data) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(ServiceSources::toArray($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
