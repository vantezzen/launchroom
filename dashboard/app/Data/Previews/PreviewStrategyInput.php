<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\PreviewStrategy;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A stateful service's "In previews" setting (docs/06 §5), set on its
 * instance in the preview base environment.
 */
final class PreviewStrategyInput extends Data
{
    /**
     * @param  list<string>|null  $sanitizeCommand  argv, run in the database image after a clone
     */
    public function __construct(
        public PreviewStrategy $strategy,
        /** The database to clone from (service instance public id). */
        #[Regex('/^sin_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $cloneSourceId = null,
        #[Max(64)]
        public ?array $sanitizeCommand = null,
    ) {}
}
