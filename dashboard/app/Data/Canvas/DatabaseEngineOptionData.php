<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\DatabaseEngine;
use Spatie\LaravelData\Data;

/**
 * An engine the "Add database" form offers, with its versions.
 */
final class DatabaseEngineOptionData extends Data
{
    /**
     * @param  list<string>  $versions
     */
    public function __construct(
        public DatabaseEngine $engine,
        public string $label,
        public array $versions,
        public string $defaultVersion,
        public string $tagline = '',
    ) {}
}
