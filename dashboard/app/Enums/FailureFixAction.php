<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The one-click fix an explained deployment failure offers
 * (App\Support\Deployments\Failures\FailureCatalogue, direction.md §9).
 * `set_port` and `raise_health_timeout` stage the setting and redeploy;
 * `add_variable` opens the variable sheet with the name filled in;
 * `generate_dockerfile` opens the guided sheet in Settings → Build.
 */
enum FailureFixAction: string
{
    case SetPort = 'set_port';
    case RaiseHealthTimeout = 'raise_health_timeout';
    case AddVariable = 'add_variable';
    /** Opens "Generate a Dockerfile" in Settings → Build (docs/20 §3). */
    case GenerateDockerfile = 'generate_dockerfile';
}
