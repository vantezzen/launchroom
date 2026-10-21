<?php

declare(strict_types=1);

namespace App\Data\Dockerfiles;

use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Settings → Dockerfile generation, the instance part (docs/20 §1). The API
 * key is hidden from serialization and only ever stored envelope-encrypted.
 */
final class UpdateAiProviderData extends Data
{
    public function __construct(
        public bool|Optional $enabled = new Optional,
        #[Max(64), Regex('/^claude-[a-z0-9][a-z0-9.-]{1,62}$/D')]
        public string|Optional $model = new Optional,
        #[Hidden, Max(512), Regex('/^[\x21-\x7e]{20,512}$/D')]
        public string|null|Optional $apiKey = new Optional,
        public bool $removeKey = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        if (is_string($properties['apiKey'] ?? null)) {
            $properties['apiKey'] = trim($properties['apiKey']);

            if ($properties['apiKey'] === '') {
                $properties['apiKey'] = null;
            }
        }

        return $properties;
    }
}
