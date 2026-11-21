<?php

declare(strict_types=1);

namespace App\Data\Transfers\Inputs;

use App\Data\Attributes\PublicIdOf;
use App\Enums\Transfers\CopySourcePoint;
use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST …/copies (docs/14 §14): copy the same service's data from another
 * environment into this one. `source` names the environment; the service is
 * matched, never picked.
 */
final class StartCopyInput extends Data
{
    public function __construct(
        #[PublicIdOf('env')]
        public string $source,
        public CopySourcePoint $point,
        /** A backup of the source (point `backup`). */
        #[Regex(Grammar::SNAPSHOT_ID)]
        public ?string $snapshot = null,
        /** The target's volume (services with volumes). */
        #[PublicIdOf('vol')]
        public ?string $volume = null,
        /** The target environment's name, when the target holds data. */
        #[Max(63)]
        public ?string $confirmation = null,
        /** The extra confirmation when the target is production. */
        public bool $replaceProduction = false,
    ) {}
}
