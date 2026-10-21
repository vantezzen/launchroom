<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Spatie\LaravelData\Data;

/**
 * One deployable app found in a repository (import flow): its folder, a
 * suggested service name, the runtime its manifest suggests and the keys of
 * its `.env.example` (never values).
 */
final class InspectedAppData extends Data
{
    /**
     * @param  list<string>  $envKeys
     */
    public function __construct(
        /** '' = repository root. */
        public string $rootDirectory,
        public string $name,
        /** node, php, python, go, ruby, rust, deno, docker or unknown. */
        public string $runtime,
        public bool $selected,
        public ?string $envExample,
        public array $envKeys,
    ) {}
}
