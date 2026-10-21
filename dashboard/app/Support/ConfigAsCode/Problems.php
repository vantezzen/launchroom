<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigExcerptLineData;
use App\Data\ConfigAsCode\ConfigFileProblemData;

/**
 * Turns "something is wrong at this JSON pointer" into a ConfigFileProblemData
 * with a line, a column, the dotted path and a five-line excerpt
 * (docs/18 §6). `mask`: the string value at the pointer must not be stored
 * (a secret-looking literal the check refused), so the excerpt shows it as
 * "••••••".
 */
final class Problems
{
    private const int CONTEXT_LINES = 2;

    private const int MAX_LINE_LENGTH = 200;

    /** @var list<string>|null */
    private ?array $lines = null;

    public function __construct(
        private readonly ?JsonDocument $document,
    ) {}

    public function at(string $pointer, string $message, ?string $hint = null, bool $key = false, bool $mask = false): ConfigFileProblemData
    {
        if (! $this->document instanceof JsonDocument) {
            return new ConfigFileProblemData($message, path: self::path($pointer), hint: $hint);
        }

        $position = $this->document->nearest($pointer, $key);

        return $this->problem($message, $position['line'], $position['column'], self::path($pointer), $hint, $mask);
    }

    public function syntax(JsonSyntaxError $error, string $content): ConfigFileProblemData
    {
        $this->lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        return $this->problem($error->getMessage(), $error->atLine, $error->atColumn, null, $error->hint, false);
    }

    /**
     * Problems as stored in jsonb columns.
     *
     * @param  list<ConfigFileProblemData>  $problems
     * @return list<array<string, mixed>>
     */
    public static function rows(array $problems): array
    {
        $rows = [];

        foreach ($problems as $problem) {
            /** @var array<string, mixed> $row */
            $row = $problem->toArray();
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * `/services/web/deploy/port` → `services.web.deploy.port`; null for the root.
     */
    public static function path(string $pointer): ?string
    {
        if ($pointer === '') {
            return null;
        }

        $segments = array_map(static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment), explode('/', substr($pointer, 1)));

        return implode('.', $segments);
    }

    /**
     * Replaces the JSON string starting at $column (1-based, characters)
     * with "••••••". A string that continues past the line is cut there.
     */
    private function maskString(string $line, int $column): string
    {
        $before = mb_substr($line, 0, $column - 1);
        $rest = mb_substr($line, $column - 1);

        if (! str_starts_with($rest, '"')) {
            return $before.'••••••';
        }

        $length = strlen($rest);

        for ($i = 1; $i < $length; $i++) {
            if ($rest[$i] === '\\') {
                $i++;

                continue;
            }

            if ($rest[$i] === '"') {
                return $before.'"••••••"'.substr($rest, $i + 1);
            }
        }

        return $before.'"••••••';
    }

    private function problem(string $message, int $line, int $column, ?string $path, ?string $hint, bool $mask): ConfigFileProblemData
    {
        $lines = $this->lines ??= $this->document?->lines() ?? [];
        $excerpt = [];
        $from = max(1, $line - self::CONTEXT_LINES);
        $to = min(count($lines), $line + self::CONTEXT_LINES);

        for ($number = $from; $number <= $to; $number++) {
            $text = $lines[$number - 1];

            if ($mask && $number === $line) {
                $text = $this->maskString($text, $column);
            }

            if (mb_strlen($text) > self::MAX_LINE_LENGTH) {
                $text = mb_substr($text, 0, self::MAX_LINE_LENGTH).'…';
            }

            $excerpt[] = new ConfigExcerptLineData($number, $text);
        }

        return new ConfigFileProblemData($message, $line, $column, $path, $hint, $excerpt);
    }
}
