<?php

declare(strict_types=1);

namespace App\Casts;

use App\Data\Services\DatabaseSource;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Data\Services\ServiceSource;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts `services.source` to the ServiceSource union (`type` discriminator).
 *
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

        return is_array($data) ? self::fromArray($data) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(self::toArray($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ServiceSource
    {
        $type = $data['type'] ?? null;
        unset($data['type']);

        return match ($type) {
            'git' => GitSource::from($data),
            'image' => ImageSource::from($data),
            'database' => DatabaseSource::from($data),
            default => throw new InvalidArgumentException('Unknown service source type.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(ServiceSource $source): array
    {
        return ['type' => $source->type(), ...$source->toArray()];
    }
}
