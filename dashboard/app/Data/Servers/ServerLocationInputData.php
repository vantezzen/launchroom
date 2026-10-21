<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A location the user entered or confirmed ("Berlin, at home"). The label is
 * display only.
 */
final class ServerLocationInputData extends Data
{
    public function __construct(
        #[Min(1), Max(64), Regex('/^[^\x00-\x1f\x7f<>]+$/Du')]
        public string $label,
        #[Between(-90, 90)]
        public float $latitude,
        #[Between(-180, 180)]
        public float $longitude,
        #[Regex('/^[A-Z]{2}$/D')]
        public ?string $countryCode = null,
    ) {}
}
