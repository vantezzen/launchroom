<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use stdClass;

/**
 * A strictly parsed JSON document (RFC 8259) that remembers where every
 * value and key starts, so errors can point at a line and column
 * (docs/18 §6). `json_decode` can do neither: it reports no positions and
 * silently keeps the last of two duplicate keys, which this parser refuses.
 *
 * Objects decode to stdClass (what the JSON Schema validator expects);
 * toArray() gives associative arrays. Positions are keyed by JSON pointer
 * (RFC 6901, "" = the root).
 */
final readonly class JsonDocument
{
    public const int MAX_BYTES = 262_144;

    public const int MAX_DEPTH = 16;

    /**
     * @param  array<string, array{int, int|null}>  $positions  pointer → [value offset, key offset]
     * @param  list<int>  $lineStarts  byte offset of every line
     */
    private function __construct(
        public string $content,
        public mixed $value,
        private array $positions,
        private array $lineStarts,
    ) {}

    /**
     * @throws JsonSyntaxError
     */
    public static function parse(string $content): self
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new JsonSyntaxError('The file is larger than 256 KB.', 1, 1, 'launchroom.json describes settings, not data: split large values out of it.');
        }

        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }

        $lineStarts = self::lineStarts($content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new JsonSyntaxError('The file is not valid UTF-8.', 1, 1, 'Save launchroom.json as UTF-8.');
        }

        $parser = new JsonDocumentParser($content, $lineStarts);
        [$value, $positions] = $parser->document();

        return new self($content, $value, $positions, $lineStarts);
    }

    /**
     * Line and column where the value at $pointer starts; null when unknown.
     *
     * @return array{line: int, column: int}|null
     */
    public function position(string $pointer): ?array
    {
        $entry = $this->positions[$pointer] ?? null;

        return $entry === null ? null : self::locate($this->content, $this->lineStarts, $entry[0]);
    }

    /**
     * Where the key of the member at $pointer starts (its value for array
     * items and the root).
     *
     * @return array{line: int, column: int}|null
     */
    public function keyPosition(string $pointer): ?array
    {
        $entry = $this->positions[$pointer] ?? null;

        return $entry === null ? null : self::locate($this->content, $this->lineStarts, $entry[1] ?? $entry[0]);
    }

    /**
     * The nearest position at or above $pointer (an error in a value the
     * parser didn't record still points somewhere close).
     *
     * @return array{line: int, column: int}
     */
    public function nearest(string $pointer, bool $key = false): array
    {
        while (true) {
            $found = $key ? $this->keyPosition($pointer) : $this->position($pointer);

            if ($found !== null) {
                return $found;
            }

            if ($pointer === '') {
                return ['line' => 1, 'column' => 1];
            }

            $pointer = substr($pointer, 0, (int) strrpos($pointer, '/'));
        }
    }

    /**
     * @return list<string> the lines of the file (without line breaks)
     */
    public function lines(): array
    {
        return preg_split('/\r\n|\n|\r/', $this->content) ?: [];
    }

    /**
     * The document with objects as associative arrays.
     */
    public function toArray(): mixed
    {
        return self::arrays($this->value);
    }

    public static function pointer(string ...$segments): string
    {
        return implode('', array_map(static fn (string $segment): string => '/'.str_replace(['~', '/'], ['~0', '~1'], $segment), $segments));
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $out = [];

            foreach (get_object_vars($value) as $key => $item) {
                $out[$key] = self::arrays($item);
            }

            return $out;
        }

        if (is_array($value)) {
            return array_map(self::arrays(...), $value);
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    private static function lineStarts(string $content): array
    {
        $starts = [0];
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === "\n" || ($char === "\r" && ($content[$i + 1] ?? '') !== "\n")) {
                $starts[] = $i + 1;
            }
        }

        return $starts;
    }

    /**
     * @param  list<int>  $lineStarts
     * @return array{line: int, column: int}
     */
    public static function locate(string $content, array $lineStarts, int $offset): array
    {
        $low = 0;
        $high = count($lineStarts) - 1;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if ($lineStarts[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        $prefix = substr($content, $lineStarts[$low], max(0, $offset - $lineStarts[$low]));

        return ['line' => $low + 1, 'column' => mb_strlen($prefix, 'UTF-8') + 1];
    }
}
