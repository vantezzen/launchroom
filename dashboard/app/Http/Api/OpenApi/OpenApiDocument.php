<?php

declare(strict_types=1);

namespace App\Http\Api\OpenApi;

use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

/**
 * The committed OpenAPI document (`resources/api/openapi.json`, docs/16
 * §2.4). `php artisan launchroom:api:openapi` writes it; a test fails when
 * it drifts from the routes and Data objects.
 */
final class OpenApiDocument
{
    public static function path(): string
    {
        return resource_path('api/openapi.json');
    }

    public static function committed(): string
    {
        if (! File::isFile(self::path())) {
            throw new RuntimeException('The OpenAPI document is missing. Run php artisan launchroom:api:openapi.');
        }

        return File::get(self::path());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function decoded(): array
    {
        $decoded = json_decode(self::committed(), true, 512, JSON_THROW_ON_ERROR);
        $document = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $document[(string) $key] = $value;
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
