<?php

declare(strict_types=1);

namespace App\Enums\Instance;

/**
 * Outcome of one control-plane check (`launchroom:doctor --json`,
 * Settings → System). The values are the doctor's contract with
 * `./launchroom doctor` (daemon/internal/doctor): never rename them.
 */
enum SystemCheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';
}
