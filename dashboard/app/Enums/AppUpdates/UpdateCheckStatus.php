<?php

declare(strict_types=1);

namespace App\Enums\AppUpdates;

/**
 * The outcome of the latest version check of a service instance
 * (`app_update_checks.status`, docs/04 §14.1).
 */
enum UpdateCheckStatus: string
{
    /** Nothing newer in the running tag's channel. */
    case Current = 'current';
    /** At least one candidate. */
    case Available = 'available';
    /** Nothing to compare (a digest without a tag, a registry launchroom can't read). */
    case Unknown = 'unknown';
    /** The registry didn't answer; checked again later. */
    case Failed = 'failed';
}
