<?php

declare(strict_types=1);

namespace App\Data\CommandPalette;

use Spatie\LaravelData\Data;

/**
 * Everything the command palette searches in the current team, plus what
 * the user may do there (UI hints; actions re-authorize).
 */
final class PaletteData extends Data
{
    /**
     * @param  list<PaletteItemData>  $projects
     * @param  list<PaletteItemData>  $environments
     * @param  list<PaletteItemData>  $services
     * @param  list<PaletteItemData>  $deployments
     * @param  list<PaletteItemData>  $servers
     */
    public function __construct(
        public array $projects,
        public array $environments,
        public array $services,
        public array $deployments,
        public array $servers,
        public bool $canDeploy,
        public bool $canWriteVariables,
    ) {}
}
