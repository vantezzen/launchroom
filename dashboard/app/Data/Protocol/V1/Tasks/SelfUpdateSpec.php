<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Updates launchroomd, or on the control-plane host the whole control plane
 * (protocol SelfUpdateSpec, docs/11 §4). The daemon checks `image` against
 * the signed release bundle of `version`.
 */
final class SelfUpdateSpec extends TaskSpec
{
    /**
     * @param  'control_plane'|null  $scope  null: the daemon only
     */
    public function __construct(
        public string $version,
        public string $image,
        public ?string $scope = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::SelfUpdate;
    }
}
