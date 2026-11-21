<?php

declare(strict_types=1);

namespace App\Data\Commands;

use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Run a one-off command (D105): an `argv` (never a shell string, docs/09
 * R1) or the `name` of a listed command, not both. The timeout defaults to
 * 10 minutes.
 */
final class StartCommandRunInput extends Data
{
    /**
     * @param  list<string>|null  $argv
     */
    public function __construct(
        /** The program and its arguments, e.g. ["php", "artisan", "migrate:status"]. */
        public ?array $argv = null,
        /** A command of the service's catalogue (`GET …/commands`), e.g. "migrate". */
        #[Regex(Grammar::SLUG)]
        public ?string $name = null,
        /** Seconds before the command is stopped (10 to 3600). */
        #[Min(10), Max(3600)]
        public ?int $timeoutSeconds = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'argv' => ['nullable', 'array', 'list', 'min:1', 'max:256', 'required_without:name', 'prohibits:name'],
            'argv.0' => ['sometimes', 'string', 'min:1'],
            'argv.*' => ['string', 'max:4096', 'not_regex:/\x00/'],
            'name' => ['nullable', 'string', 'required_without:argv'],
        ];
    }
}
