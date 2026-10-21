<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Actions\Deployments\AdvanceDeployment;
use App\Models\Deployment;
use App\Services\Builds\BuildRouting;

/**
 * Builds for a server move's target platform (docs/14 §13.3): when a
 * stateful service built from source moves to a server of another CPU
 * architecture, its image is built for the target while it still runs on
 * the source. The deployment carries `plan.prebuildFor`; the build routes
 * to that platform ({@see BuildRouting}), and once the
 * image exists the deployment ends as superseded without rolling out
 * ({@see AdvanceDeployment}). The move's own
 * deployment on the target then reuses the image (same build key).
 */
final class Prebuilds
{
    public const string KEY = 'prebuildFor';

    public const array PLATFORMS = ['linux/amd64', 'linux/arm64'];

    /**
     * The platform $deployment builds for ahead of a move, or null.
     */
    public static function platform(Deployment $deployment): ?string
    {
        $platform = $deployment->plan[self::KEY] ?? null;

        return is_string($platform) && in_array($platform, self::PLATFORMS, true) ? $platform : null;
    }
}
