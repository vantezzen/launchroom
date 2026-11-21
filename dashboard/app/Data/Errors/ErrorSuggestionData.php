<?php

declare(strict_types=1);

namespace App\Data\Errors;

use Spatie\LaravelData\Data;

/** One "Did you mean" link on a 404: something the user may open. */
final class ErrorSuggestionData extends Data
{
    public function __construct(
        public string $label,
        public string $href,
        /** A quiet second line (the project of an environment, a service's kind). */
        public ?string $detail = null,
    ) {}
}
