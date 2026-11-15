<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use App\Data\Attributes\PublicIdOf;
use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST …/exports (docs/14 §12.7): the data as it is now, or a backup snapshot.
 */
final class StartExportInput extends Data
{
    public function __construct(
        #[PublicIdOf('vol')]
        public ?string $volume = null,
        #[Regex(Grammar::SNAPSHOT_ID)]
        public ?string $snapshot = null,
    ) {}
}
