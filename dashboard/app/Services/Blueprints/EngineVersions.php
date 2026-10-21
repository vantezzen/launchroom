<?php

declare(strict_types=1);

namespace App\Services\Blueprints;

use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\DatabaseEngine;

/**
 * The managed engines' versions in the shape the pure translators take
 * (App\Support\Compose\TranslationOptions::$engines).
 */
final readonly class EngineVersions
{
    public function __construct(
        private DatabaseEngines $engines,
    ) {}

    /**
     * @return array<string, array{versions: list<string>, default: string}>
     */
    public function all(): array
    {
        $all = [];

        foreach (DatabaseEngine::cases() as $engine) {
            $driver = $this->engines->for($engine);
            $all[$engine->value] = ['versions' => $driver->versions(), 'default' => $driver->defaultVersion()];
        }

        return $all;
    }
}
