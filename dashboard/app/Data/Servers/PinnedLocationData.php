<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * `servers.location`: the location the user confirmed or entered. The label
 * is display only.
 */
final class PinnedLocationData extends Data
{
    public function __construct(
        public string $label,
        public float $latitude,
        public float $longitude,
        public ?string $countryCode = null,
    ) {}

    public static function fromInput(ServerLocationInputData $input): self
    {
        return new self(trim($input->label), round($input->latitude, 4), round($input->longitude, 4), $input->countryCode);
    }
}
