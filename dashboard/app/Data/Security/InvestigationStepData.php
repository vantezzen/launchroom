<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One of "What to do now" (docs/22 §7.5), mapped to an existing action.
 */
final class InvestigationStepData extends Data
{
    /**
     * @param  list<InvestigationLinkData>  $links
     */
    public function __construct(
        /** `update_package`, `change_image`, `redeploy`, `restore`, `rotate_secrets`, `database_password`, `block`, `advisory`. */
        public string $kind,
        public string $text,
        public ?string $command = null,
        public array $links = [],
    ) {}
}
