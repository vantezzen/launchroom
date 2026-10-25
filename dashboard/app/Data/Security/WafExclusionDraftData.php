<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * "Allow requests like this" prefilled from an event (docs/22 §6.4): the
 * rule, the event's path cut to its static prefix, the matched target.
 */
final class WafExclusionDraftData extends Data
{
    public function __construct(
        public int $rule,
        public ?string $path,
        public ?string $target,
        /** The service settings' firewall page, where the exception is added. */
        public string $settingsHref,
        public string $exclusionsUrl,
    ) {}
}
