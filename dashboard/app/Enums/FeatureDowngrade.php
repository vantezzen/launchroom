<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the NodeState renderer does with an optional field for a launchroomd
 * that didn't announce its feature (App\Services\NodeStates\FeatureGate).
 * Decoding is strict, so an older daemon would refuse the whole document.
 */
enum FeatureDowngrade: string
{
    /** The field is left out after rendering; nothing else depends on it. */
    case DropField = 'drop_field';
    /** The list item carrying it (a route, a volume) is left out after rendering. */
    case DropItem = 'drop_item';
    /**
     * The renderer renders the item the way it did before the feature, because
     * the field changes other fields (a cron's restart policy and port, a
     * database's command and args) or other items (routes to a workload).
     */
    case RenderWithout = 'render_without';
}
