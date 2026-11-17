<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a failing HTTP health check's answer means (report.json
 * ProbeStatus.hint, Go `state.ProbeHint`). Read leniently.
 */
enum ProbeHint: string
{
    /** The app answered 400/403/421 with a "host not allowed" page: it rejects its own domain. */
    case HostNotAllowed = 'host_not_allowed';
}
