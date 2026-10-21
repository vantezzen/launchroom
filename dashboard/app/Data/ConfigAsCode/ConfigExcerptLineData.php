<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

/**
 * One line of a launchroom.json excerpt around a problem (docs/18 §6).
 */
final class ConfigExcerptLineData extends Data
{
    public function __construct(
        public int $number,
        public string $text,
    ) {}
}
