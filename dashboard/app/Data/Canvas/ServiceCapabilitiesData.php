<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

/**
 * What a service of this kind can do, from ServiceKind, so the panel and
 * settings never restate kind lists.
 */
final class ServiceCapabilitiesData extends Data
{
    public function __construct(
        public bool $web,
        public bool $built,
        public bool $stateful,
        /** Takes connections on a port (workers and crons run their parent's image without listening). */
        public bool $listens,
        public bool $ownsVolumes,
        public bool $canMountSharedVolume,
        public bool $deploysNewVersions,
    ) {}

    public static function of(ServiceKind $kind): self
    {
        return new self(
            web: $kind->isWeb(),
            built: $kind->isBuilt(),
            stateful: $kind->isStateful(),
            listens: ! $kind->requiresParent(),
            ownsVolumes: $kind->ownsVolumes(),
            canMountSharedVolume: $kind->canMountSharedVolume(),
            deploysNewVersions: $kind->deploysNewVersions(),
        );
    }
}
