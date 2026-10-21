<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Enums\DatabaseEngine;

/**
 * The typed registry of engine drivers (docs/10 §2.1).
 */
final readonly class DatabaseEngines
{
    public function __construct(
        private ConfiguredImages $images,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, array<string, string>> $images */
        $images = array_map(
            static fn (mixed $engine): array => is_array($engine) && is_array($engine['images'] ?? null) ? array_filter($engine['images'], is_string(...)) : [],
            config()->array('launchroom.database_engines'),
        );

        return new self(new ConfiguredImages($images));
    }

    public function for(DatabaseEngine $engine): DatabaseEngineDriver
    {
        return match ($engine) {
            DatabaseEngine::Postgres => new Postgres($this->images),
            DatabaseEngine::MySql => new MySql($this->images),
            DatabaseEngine::Redis => new Redis($this->images),
            DatabaseEngine::Valkey => new Valkey($this->images),
            DatabaseEngine::MongoDb => new MongoDb($this->images),
            DatabaseEngine::ClickHouse => new ClickHouse($this->images),
        };
    }
}
