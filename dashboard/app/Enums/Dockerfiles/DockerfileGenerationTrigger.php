<?php

declare(strict_types=1);

namespace App\Enums\Dockerfiles;

/**
 * Why a generation was started (docs/20 §3).
 */
enum DockerfileGenerationTrigger: string
{
    /** The plan preview or a deployment failed with plan_error. */
    case PlanFailed = 'plan_failed';
    /** A build failed because nothing says how to start the app. */
    case BuildFailed = 'build_failed';
    /** Settings → Build, on demand. */
    case OnDemand = 'on_demand';
}
