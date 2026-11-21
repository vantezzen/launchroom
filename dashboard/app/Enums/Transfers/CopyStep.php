<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * The steps of a copy between environments, in order (docs/14 §14.2).
 */
enum CopyStep: string
{
    case Export = 'export';
    case Snapshot = 'snapshot';
    case Stop = 'stop';
    case Import = 'import';
    case Start = 'start';
}
