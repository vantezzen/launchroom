<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use stdClass;

/**
 * The recursive-descent parser behind JsonDocument. Strict RFC 8259 with
 * friendly messages for the usual mistakes (comments, trailing commas,
 * single quotes, unquoted keys, duplicate keys).
 *
 * @internal
 */
final class JsonDocumentParser
{
    private int $offset = 0;

    private readonly int $length;

    /** @var array<string, array{int, int|null}> */
    private array $positions = [];

    /**
     * @param  list<int>  $lineStarts
     */
    public function __construct(
        private readonly string $source,
        private readonly array $lineStarts,
    ) {
        $this->length = strlen($source);
    }

    /**
     * @return array{mixed, array<string, array{int, int|null}>}
     *
     * @throws JsonSyntaxError
     */
    public function document(): array
    {
        $this->whitespace();

        if ($this->offset >= $this->length) {
            $this->fail('The file is empty.', 'Start with { "version": 1, "services": {} }.');
        }

        $value = $this->value('', null, 0);
        $this->whitespace();

        if ($this->offset < $this->length) {
            $this->fail('Unexpected '.$this->describe().' after the end of the document.', 'A JSON file holds one object: remove what follows its closing }.');
        }

        return [$value, $this->positions];
    }

    private function value(string $pointer, ?int $keyOffset, int $depth): mixed
    {
        if ($depth > JsonDocument::MAX_DEPTH) {
            $this->fail('The file is nested too deeply.', 'launchroom.json needs at most '.JsonDocument::MAX_DEPTH.' levels.');
        }

        $this->positions[$pointer] = [$this->offset, $keyOffset];
        $char = $this->source[$this->offset] ?? '';

        return match (true) {
            $char === '{' => $this->object($pointer, $depth),
            $char === '[' => $this->list($pointer, $depth),
            $char === '"' => $this->string(),
            $char === '-' || ctype_digit($char) => $this->number(),
            default => $this->literal(),
        };
    }

    private function object(string $pointer, int $depth): stdClass
    {
        $object = new stdClass;
        /** @var array<string, int> $seen key → offset */
        $seen = [];
        $this->offset++;
        $this->whitespace();

        if ($this->peek() === '}') {
            $this->offset++;

            return $object;
        }

        while (true) {
            $this->whitespace();
            $keyOffset = $this->offset;
            $char = $this->peek();

            if ($char === '}') {
                $this->fail('Unexpected } after a comma.', 'Remove the trailing comma before }: JSON doesn\'t allow one.', $this->lastComma());
            }

            if ($char === "'") {
                $this->fail('Keys need double quotes.', 'Write "key", not \'key\'.');
            }

            if ($char !== '"') {
                $this->fail('Expected a key in double quotes, found '.$this->describe().'.', ctype_alpha($char) || $char === '_' ? 'Put the key in double quotes: "'.$this->word().'".' : null);
            }

            $key = $this->string();

            if (isset($seen[$key])) {
                $first = JsonDocument::locate($this->source, $this->lineStarts, $seen[$key]);
                $this->fail("\"{$key}\" is set twice in this object (first on line {$first['line']}).", 'Keep one of them: JSON objects can\'t repeat a key.', $keyOffset);
            }

            $seen[$key] = $keyOffset;
            $this->whitespace();

            if ($this->peek() !== ':') {
                $this->fail('Expected : after "'.$key.'", found '.$this->describe().'.');
            }

            $this->offset++;
            $this->whitespace();

            if ($this->offset >= $this->length) {
                $this->fail('The file ends in the middle of "'.$key.'".');
            }

            $object->{$key} = $this->value($pointer.JsonDocument::pointer($key), $keyOffset, $depth + 1);
            $this->whitespace();
            $next = $this->peek();

            if ($next === ',') {
                $this->offset++;

                continue;
            }

            if ($next === '}') {
                $this->offset++;

                return $object;
            }

            $this->fail('Expected , or } after "'.$key.'", found '.$this->describe().'.', $next === '"' ? 'Add a comma between the two entries.' : null);
        }
    }

    /**
     * @return list<mixed>
     */
    private function list(string $pointer, int $depth): array
    {
        $items = [];
        $this->offset++;
        $this->whitespace();

        if ($this->peek() === ']') {
            $this->offset++;

            return $items;
        }

        while (true) {
            $this->whitespace();

            if ($this->peek() === ']') {
                $this->fail('Unexpected ] after a comma.', 'Remove the trailing comma before ]: JSON doesn\'t allow one.', $this->lastComma());
            }

            if ($this->offset >= $this->length) {
                $this->fail('The file ends inside a list.', 'Close it with ].');
            }

            $items[] = $this->value($pointer.'/'.count($items), null, $depth + 1);
            $this->whitespace();
            $next = $this->peek();

            if ($next === ',') {
                $this->offset++;

                continue;
            }

            if ($next === ']') {
                $this->offset++;

                return $items;
            }

            $this->fail('Expected , or ] in this list, found '.$this->describe().'.', $next === '' ? 'Close the list with ].' : 'Add a comma between the two items.');
        }
    }

    private function string(): string
    {
        $start = $this->offset;
        $this->offset++;
        $out = '';

        while (true) {
            if ($this->offset >= $this->length) {
                $this->fail('This string is never closed.', 'Add the closing ".', $start);
            }

            $char = $this->source[$this->offset];

            if ($char === '"') {
                $this->offset++;

                return $out;
            }

            if (ord($char) < 0x20) {
                $this->fail($char === "\n" ? 'A line break inside a string.' : 'A control character inside a string.', 'Write line breaks as \n inside strings.');
            }

            if ($char !== '\\') {
                // Copy a run of plain bytes at once (UTF-8 is already checked).
                $run = strcspn($this->source, "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f", $this->offset);
                $out .= substr($this->source, $this->offset, $run);
                $this->offset += $run;

                continue;
            }

            $escape = $this->source[$this->offset + 1] ?? '';
            $this->offset += 2;

            $out .= match ($escape) {
                '"' => '"',
                '\\' => '\\',
                '/' => '/',
                'b' => "\x08",
                'f' => "\f",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'u' => $this->unicode(),
                default => $this->fail('Unknown escape \\'.$escape.' in a string.', 'Valid escapes are \" \\\\ \/ \b \f \n \r \t and \uXXXX.', $this->offset - 2),
            };
        }
    }

    private function unicode(): string
    {
        $code = $this->hex4();

        if ($code >= 0xD800 && $code <= 0xDBFF) {
            if (substr($this->source, $this->offset, 2) !== '\\u') {
                $this->fail('An unpaired \u surrogate in a string.');
            }

            $this->offset += 2;
            $low = $this->hex4();

            if ($low < 0xDC00 || $low > 0xDFFF) {
                $this->fail('An unpaired \u surrogate in a string.');
            }

            $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
        } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
            $this->fail('An unpaired \u surrogate in a string.');
        }

        return mb_chr($code, 'UTF-8') ?: '';
    }

    private function hex4(): int
    {
        $hex = substr($this->source, $this->offset, 4);

        if (strlen($hex) !== 4 || ! ctype_xdigit($hex)) {
            $this->fail('\u needs four hex digits.');
        }

        $this->offset += 4;

        return (int) hexdec($hex);
    }

    private function number(): int|float
    {
        if (preg_match('/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A', $this->source, $m, 0, $this->offset) !== 1) {
            $this->fail('This number is not valid JSON.');
        }

        $text = $m[0];
        $this->offset += strlen($text);
        $next = $this->peek();

        if ($next !== '' && (ctype_alnum($next) || $next === '.')) {
            $this->fail('This number is not valid JSON.', str_starts_with($text, '0') && strlen($text) > 1 ? null : 'Numbers have no leading zeros, units or trailing dots.');
        }

        if (preg_match('/^-?[0-9]{1,18}$/D', $text) === 1) {
            return (int) $text;
        }

        return (float) $text;
    }

    private function literal(): ?bool
    {
        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $value) {
            if (substr($this->source, $this->offset, strlen($word)) === $word && ! ctype_alnum($this->source[$this->offset + strlen($word)] ?? ' ')) {
                $this->offset += strlen($word);

                return $value;
            }
        }

        $char = $this->peek();

        if ($char === "'") {
            $this->fail('Strings need double quotes.', 'Write "text", not \'text\'.');
        }

        if ($char === '') {
            $this->fail('The file ends where a value should be.');
        }

        $word = $this->word();

        if (in_array($word, ['True', 'False', 'TRUE', 'FALSE', 'None', 'NULL', 'Null'], true)) {
            $this->fail("{$word} is not JSON.", 'Use lowercase true, false or null.');
        }

        if (in_array($word, ['NaN', 'Infinity'], true)) {
            $this->fail("{$word} is not a JSON number.");
        }

        $this->fail('Unexpected '.$this->describe().'.', $word !== '' ? 'Put text in double quotes: "'.$word.'".' : null);
    }

    private function whitespace(): void
    {
        while ($this->offset < $this->length) {
            $char = $this->source[$this->offset];

            if (in_array($char, [' ', "\t", "\n", "\r"], true)) {
                $this->offset++;

                continue;
            }

            if ($char === '/' && in_array($this->source[$this->offset + 1] ?? '', ['/', '*'], true)) {
                $this->fail('Comments aren\'t allowed in JSON.', 'Remove the comment. Explain settings in a README or the commit message instead.');
            }

            if ($char === '#') {
                $this->fail('Comments aren\'t allowed in JSON.', 'Remove the comment. Explain settings in a README or the commit message instead.');
            }

            return;
        }
    }

    private function peek(): string
    {
        return $this->source[$this->offset] ?? '';
    }

    private function word(): string
    {
        preg_match('/[A-Za-z0-9_$.-]*/A', $this->source, $m, 0, $this->offset);

        return mb_substr($m[0] ?? '', 0, 40);
    }

    private function describe(): string
    {
        if ($this->offset >= $this->length) {
            return 'the end of the file';
        }

        $char = mb_substr(substr($this->source, $this->offset, 4), 0, 1, 'UTF-8');

        return match ($char) {
            "\n", "\r" => 'a line break',
            '"' => 'a string',
            default => "\"{$char}\"",
        };
    }

    /**
     * Offset of the comma before the current position (for trailing commas).
     */
    private function lastComma(): int
    {
        $comma = strrpos(substr($this->source, 0, $this->offset), ',');

        return $comma === false ? $this->offset : $comma;
    }

    /**
     * @throws JsonSyntaxError
     */
    private function fail(string $message, ?string $hint = null, ?int $at = null): never
    {
        $position = JsonDocument::locate($this->source, $this->lineStarts, min($at ?? $this->offset, $this->length));

        throw new JsonSyntaxError($message, $position['line'], $position['column'], $hint);
    }
}
