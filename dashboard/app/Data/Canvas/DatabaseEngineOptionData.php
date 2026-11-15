<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Drivers\DatabaseEngines\DatabaseEngineCategory;
use App\Drivers\DatabaseEngines\DatabaseEngineDriver;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use Spatie\LaravelData\Data;

/**
 * An engine the "Add database" form offers, with its versions.
 */
final class DatabaseEngineOptionData extends Data
{
    /**
     * @param  list<string>  $versions
     * @param  list<string>  $provides  every variable a connection can inject, in any style
     */
    public function __construct(
        public DatabaseEngine $engine,
        public string $label,
        public array $versions,
        public string $defaultVersion,
        public string $tagline = '',
        public array $provides = [],
        public DatabaseEngineCategory $category = DatabaseEngineCategory::Relational,
    ) {}

    public static function fromDriver(DatabaseEngineDriver $driver): self
    {
        $engine = $driver->engine();
        $provides = $driver->exportedKeys();

        foreach (ConnectionStyle::cases() as $style) {
            $provides = [...$provides, ...array_keys($driver->connectionVariables($style, 'db'))];
        }

        return new self($engine, $engine->label(), $driver->versions(), $driver->defaultVersion(), $engine->tagline(), array_values(array_unique($provides)), $driver->category());
    }
}
