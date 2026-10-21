<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a promotion entry describes (docs/06 §6): the unit Compare & promote
 * offers, matched across environments by a logical path (service public id,
 * variable key, mount path) instead of per-environment row ids.
 */
enum PromotionEntryType: string
{
    case Service = 'service';
    /** An instance setting: enabled, branchOverride, build.*, deploy.*, scaling.*. */
    case Field = 'field';
    case Variable = 'variable';
    case SharedVariable = 'shared_variable';
    /** A service opted into a shared variable. */
    case SharedUsage = 'shared_usage';
    case Volume = 'volume';
    case Connection = 'connection';
    case Triggers = 'triggers';
}
