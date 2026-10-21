<?php

declare(strict_types=1);

namespace App\Data\StagedChanges;

use Spatie\LaravelData\Data;

/**
 * A commit pinned by "Also promote images" (docs/06 §6.4): the next Deploy
 * deploys it for the service (reusing the image when build inputs match).
 */
final class PromotedImageData extends Data
{
    public function __construct(
        public string $serviceId,
        public string $serviceName,
        public string $commitSha,
        public string $deploymentId,
        public string $fromEnvironment,
    ) {}
}
