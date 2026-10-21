<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * What the signed-in user may do in a project. UI hints only: every action
 * re-authorizes on the server.
 */
final class ProjectAbilitiesData extends Data
{
    public function __construct(
        /** Add, configure and remove services, domains, layout. */
        public bool $manage,
        /** Connections inject variables: needs manage + variable write. */
        public bool $connect,
        public bool $deploy,
    ) {}
}
