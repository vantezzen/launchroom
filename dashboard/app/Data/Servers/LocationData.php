<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * Where a server is (map bubbles, card chip). `confirmed` is false for a
 * suggestion (from a region code or the host's time zone) that the user
 * hasn't confirmed yet: the UI asks "Where is fra-1?" once.
 */
final class LocationData extends Data
{
    public const string SOURCE_USER = 'user';

    public const string SOURCE_REGION = 'region';

    public const string SOURCE_TIMEZONE = 'timezone';

    public function __construct(
        public string $label,
        public float $latitude,
        public float $longitude,
        /** ISO 3166-1 alpha-2, when known. */
        public ?string $countryCode,
        /** user | region | timezone */
        public string $source,
        public bool $confirmed,
    ) {}
}
