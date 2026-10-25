<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * Where something runs: a service in a project's environment, with the
 * link to its panel.
 */
final class SecurityServiceRefData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        /** Logo key of the service (`nextjs`, `postgres`, …) or null. */
        public ?string $logo,
        public string $kind,
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $environmentName,
        public string $href,
    ) {}
}
