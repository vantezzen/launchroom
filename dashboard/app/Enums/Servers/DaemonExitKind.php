<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * How launchroomd's previous run ended when it wasn't a clean stop
 * (Report.host.daemon.previousExit, `servers.last_daemon_exit`).
 */
enum DaemonExitKind: string
{
    /** It crashed; the panic trace is kept for admins. */
    case Crash = 'crash';
    /** Its own watchdog found a loop stuck and restarted it. */
    case Stalled = 'stalled';
    /** The machine restarted. */
    case Reboot = 'reboot';
    /** It stopped without a crash report (killed, out of memory, power loss). */
    case Unexpected = 'unexpected';
}
