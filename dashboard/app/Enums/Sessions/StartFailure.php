<?php

declare(strict_types=1);

namespace App\Enums\Sessions;

/**
 * Why launchroomd could not start a session or temporary access (SessionResult.failure, AccessResult.failure, Go `state.StartFailure`, docs/03 §11.4).
 */
enum StartFailure: string
{
    case NoShell = 'no_shell';
    case ShellMissing = 'shell_missing';
    case ShellStartFailed = 'shell_start_failed';
    case KindDisabled = 'kind_disabled';
    case GuardRefused = 'guard_refused';
    case PolicyRefused = 'policy_refused';
    case NotRunning = 'not_running';
    case RelayUnreachable = 'relay_unreachable';
    case SessionKey = 'session_key';
}
