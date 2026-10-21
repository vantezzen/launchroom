<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a field behaves when promoting configuration between environments
 * (docs/06 §6.2). Declared on Data properties with #[App\Data\Attributes\Promotion].
 */
enum PromotionClass: string
{
    /** Included by default (services, commands, healthcheck, variable existence). */
    case Structural = 'structural';

    /** Shown but unchecked (domains, replicas, resources, triggers). */
    case EnvironmentSpecific = 'environment_specific';

    /** Never promoted (secret values, generated credentials, volume data). */
    case Never = 'never';
}
