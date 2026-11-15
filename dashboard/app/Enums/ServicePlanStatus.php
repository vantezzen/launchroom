<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The latest plan preview of a service instance (`service_instances.plan`,
 * docs/04 §4).
 */
enum ServicePlanStatus: string
{
    /** A plan task is queued or running. */
    case Planning = 'planning';
    case Ready = 'ready';
    case Failed = 'failed';
}
