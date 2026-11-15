<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which target put a variable into an environment's spec (docs/06 §3). A
 * preview branch override wins over the environment's own value.
 */
enum VariableLayer: string
{
    case Environment = 'environment';
    case Branch = 'branch';
}
