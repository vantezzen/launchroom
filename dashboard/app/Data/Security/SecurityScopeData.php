<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * What the Security page is narrowed to (docs/22 §9.2): one service (all
 * its environments) or one project, from `?service=` or `?project=`.
 */
final class SecurityScopeData extends Data
{
    public function __construct(
        /** project | service */
        public string $kind,
        /** `prj_…` or `svc_…` */
        public string $id,
        public string $name,
        /** The project of a service, for the chip ("api in shop"). */
        public ?string $projectName = null,
    ) {}
}
