<?php

declare(strict_types=1);

namespace App\Services\Holds;

use App\Data\Holds\Hold;

/**
 * A workflow that holds services while it runs (restores, imports, moves).
 */
interface HoldSource
{
    /**
     * The holds of every run that holds services right now, oldest first.
     *
     * @return list<Hold>
     */
    public static function active(): array;
}
