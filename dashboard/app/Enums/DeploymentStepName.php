<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Timeline steps of a deployment (docs/07 `deployment_steps`).
 */
enum DeploymentStepName: string
{
    case Plan = 'plan';
    case Build = 'build';
    case Release = 'release';
    case Rollout = 'rollout';
    case Health = 'health';
    case Route = 'route';
    case Cleanup = 'cleanup';
}
