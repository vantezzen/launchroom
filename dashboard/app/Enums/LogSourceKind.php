<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a log chunk belongs to (protocol/schema/v1/log-batch.json).
 */
enum LogSourceKind: string
{
    case Task = 'task';
    case Deployment = 'deployment';
    case Workload = 'workload';
}
