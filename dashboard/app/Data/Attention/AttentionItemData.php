<?php

declare(strict_types=1);

namespace App\Data\Attention;

use App\Enums\AttentionState;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One row of "Needs you" on the projects page (docs/08 §13): what needs the
 * user, where, and a link to the place that fixes it.
 */
final class AttentionItemData extends Data
{
    public function __construct(
        /** Stable across reloads (a React key). */
        public string $key,
        /** service | server | staged | security | paused | preview */
        public string $kind,
        public AttentionState $state,
        /** "api failed to deploy". */
        public string $title,
        /** Where: "shop", "shop, staging". */
        public ?string $context,
        /** One more fact: "Still serving the previous version". */
        public ?string $detail,
        public ?CarbonInterface $at,
        public string $href,
        /** The link's words: "See why", "Review", "Open". */
        public string $action,
    ) {}
}
