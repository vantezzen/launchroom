<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Data;

/**
 * The previews rule of a project in words (DPL-6): which pull requests get
 * a preview, a copy of which environment, and when it goes away. The
 * previews page writes it as one sentence under its title.
 */
final class PreviewRuleData extends Data
{
    /**
     * @param  list<string>  $branches  base branch globs; empty means every pull request
     */
    public function __construct(
        /** The environment previews copy (null: none deployed yet). */
        public ?string $baseEnvironment,
        public array $branches,
        public ?string $requiredLabel,
        /** Days without activity before a preview is removed (0: never). */
        public int $expiryDays,
    ) {}
}
