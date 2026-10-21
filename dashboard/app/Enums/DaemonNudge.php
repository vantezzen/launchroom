<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Content-free realtime nudges on a daemon's private channel (docs/03 §4).
 * The daemon reacts by fetching through the signed HTTP channel.
 */
enum DaemonNudge: string
{
    /** A new NodeState version exists: fetch GET /daemon/v1/state now. */
    case StateChanged = 'state.changed';
    /** A task this server may lease exists: POST /daemon/v1/tasks/lease now. */
    case TaskAvailable = 'task.available';
    /** A logs task was created for this server (lease now). */
    case LogsRequested = 'logs.requested';
}
