<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * "Allow requests like this" prefilled from an event (docs/22 §6.4): one
 * exception per rule that scored, all for the event's path cut to its
 * static prefix, each with the matched target where it is known.
 */
final class WafExclusionDraftData extends Data
{
    /**
     * @param  list<WafExclusionDraftRuleData>  $rules
     * @param  list<int>  $uncovered
     */
    public function __construct(
        public array $rules,
        public ?string $path,
        /** Scoring rules no exception can name (outside 911000–944999): requests like this may still be blocked. */
        public array $uncovered,
        /** The service settings' firewall page, where exceptions are edited by hand. */
        public string $settingsHref,
        /** Adds all of `rules` in one step. */
        public string $allowUrl,
    ) {}
}
