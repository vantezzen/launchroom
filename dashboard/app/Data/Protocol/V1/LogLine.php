<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class LogLine extends Data
{
    public function __construct(
        #[Regex(Report::TIME_PATTERN)]
        public string $time,
        #[In(['stdout', 'stderr', 'system'])]
        public string $stream,
        /** May be empty: programs print blank lines (validated in rules()). */
        public string $text,
        /** Build/release step the line belongs to (`clone`, `install`, `run`, …). */
        #[Max(128)]
        public string|Optional $step = new Optional,
    ) {}

    /**
     * A blank output line is a line too. The inferred `required` rule would
     * refuse "" and with it the whole batch (live tails shipped nothing as
     * soon as a container had printed one empty line).
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['text' => ['present', 'string', 'max:65536']];
    }
}
