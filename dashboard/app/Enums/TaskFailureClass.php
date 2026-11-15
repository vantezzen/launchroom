<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a build or plan task failed (protocol FailureClass, Go
 * `state.FailureClass`, docs/04 §6).
 */
enum TaskFailureClass: string
{
    case PlanError = 'plan_error';
    case BuildError = 'build_error';
    case Transient = 'transient';
    case CloneError = 'clone_error';
    case ConfigError = 'config_error';
    case Timeout = 'timeout';
    case InternalError = 'internal_error';

    public function deploymentClass(): DeploymentFailureClass
    {
        return match ($this) {
            self::PlanError => DeploymentFailureClass::PlanError,
            self::BuildError => DeploymentFailureClass::BuildError,
            self::Transient, self::InternalError => DeploymentFailureClass::Transient,
            self::CloneError, self::ConfigError => DeploymentFailureClass::ConfigError,
            self::Timeout => DeploymentFailureClass::BuildTimeout,
        };
    }

    public function retryable(): bool
    {
        return $this === self::Transient;
    }
}
