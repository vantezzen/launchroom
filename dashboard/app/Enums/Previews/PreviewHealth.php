<?php

declare(strict_types=1);

namespace App\Enums\Previews;

/**
 * A preview's current deployments in one word (PreviewList, the API's
 * PreviewResource).
 */
enum PreviewHealth: string
{
    /** No environment (queued, skipped, closed). */
    case None = 'none';
    case Building = 'building';
    /** Every service is live. */
    case Live = 'live';
    /** One of its deployments failed. */
    case Failed = 'failed';
    /** Nothing is deployed. */
    case Stopped = 'stopped';
}
