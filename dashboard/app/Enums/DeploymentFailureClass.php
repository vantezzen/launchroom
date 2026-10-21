<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a deployment failed (docs/04 §6), shown prominently with hints.
 */
enum DeploymentFailureClass: string
{
    case PlanError = 'plan_error';
    case BuildError = 'build_error';
    /** Registry or network trouble (retried once by the build wave). */
    case Transient = 'transient';
    case ReleaseError = 'release_error';
    case RuntimeError = 'runtime_error';
    case PortMismatch = 'port_mismatch';
    case HealthTimeout = 'health_timeout';
    /** Broken references, missing image digest: fix the configuration. */
    case ConfigError = 'config_error';
    /** No eligible server (roles, labels, capacity, pinned volume offline). */
    case PlacementError = 'placement_error';
}
