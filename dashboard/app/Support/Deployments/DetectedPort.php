<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Specs\ServiceSpec;
use App\Enums\PortHint;
use App\Models\Placement;

/**
 * The port launchroom may adopt for an app that listens elsewhere (OD14,
 * App\Actions\Deployments\AdoptDetectedPort).
 */
final class DetectedPort
{
    /**
     * The port to adopt for a placement's port mismatch, or null: only for a
     * web service without a port of its own whose app listens on exactly one
     * other port.
     */
    public static function adoptable(ServiceSpec $spec, Placement $placement): ?int
    {
        $issue = $placement->issue;

        if ($spec->deploy->port !== null || ! $spec->kind->isWeb() || $issue === null || $issue->kind->portHint() !== PortHint::WrongPort) {
            return null;
        }

        $ports = array_values(array_unique($issue->listening));

        return count($ports) === 1 && $ports[0] >= 1 && $ports[0] <= 65535 ? $ports[0] : null;
    }
}
