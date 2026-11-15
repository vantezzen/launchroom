<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The answer to "what will launchroom build from this repository?" while
 * adding a service.
 */
enum PlanPreviewStatus: string
{
    case Ready = 'ready';
    /** A plan task is running: poll again. */
    case Pending = 'pending';
    case Failed = 'failed';
    /** Detection happens with the first build instead. */
    case Unavailable = 'unavailable';
}
