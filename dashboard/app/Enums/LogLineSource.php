<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a line in the log explorer came from: a build or release step, or a
 * running service.
 */
enum LogLineSource: string
{
    case Build = 'build';
    case Runtime = 'runtime';
}
