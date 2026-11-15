<?php

declare(strict_types=1);

namespace App\Enums;

enum CanvasEdgeType: string
{
    /** A connection: injects variables. */
    case Connection = 'connection';
    /** A worker or cron to its parent app; not removable. */
    case Process = 'process';
    /** What a worker or cron gets from its app's connection. */
    case Inherited = 'inherited';
}
