<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use App\Enums\BlueprintNoteKind;
use Spatie\LaravelData\Data;

/**
 * One sentence about a line of the source (docs/17 §2.4), with the dotted
 * field path (`services.web.privileged`) and its line when known. Shown in
 * the preview; never applied.
 */
final class BlueprintNote extends Data
{
    public function __construct(
        public BlueprintNoteKind $kind,
        public string $message,
        public ?string $field = null,
        public ?int $line = null,
    ) {}
}
