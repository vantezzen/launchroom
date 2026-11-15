<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stateful services of a "Replace server" run are restored on the
 * replacement first; stateless ones are re-placed after them (docs/14 §5.3).
 */
enum ReplacedServiceKind: string
{
    case Stateful = 'stateful';
    case Stateless = 'stateless';
}
