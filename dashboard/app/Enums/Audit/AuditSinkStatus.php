<?php

declare(strict_types=1);

namespace App\Enums\Audit;

/**
 * A sink's delivery state, derived from its row (docs/09 R34).
 */
enum AuditSinkStatus: string
{
    /** Nothing delivered yet. */
    case Pending = 'pending';
    /** Caught up, or catching up without errors. */
    case Delivering = 'delivering';
    /** The last attempt failed; retried with backoff. */
    case Failing = 'failing';
    case Paused = 'paused';
}
