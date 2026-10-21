<?php

declare(strict_types=1);

namespace App\Support\Compose;

/**
 * Line numbers of block-style YAML keys and sequence items by dotted path
 * (`services.web.ports.0` → 12), so notes can point at the line they are
 * about. A small indentation scanner, not a second YAML parser: flow
 * mappings, multi-line flow values and keys from merges (`<<: *base`) get
 * the line of their nearest indexed parent.
 */
final readonly class YamlLineIndex
{
    /**
     * @param  array<string, int>  $lines
     */
    private function __construct(
        private array $lines,
    ) {}

    public static function of(string $yaml): self
    {
        $lines = [];
        /** @var list<array{indent: int, key: string, item: bool, count: int}> $stack */
        $stack = [];
        $blockIndent = null;

        foreach (preg_split('/\r\n|\r|\n/', $yaml) ?: [] as $number => $raw) {
            $line = $number + 1;
            $content = ltrim($raw, ' ');
            $indent = strlen($raw) - strlen($content);

            // Inside a block scalar (`key: |`): skip lines indented under it.
            if ($blockIndent !== null) {
                if (trim($raw) === '' || $indent > $blockIndent) {
                    continue;
                }

                $blockIndent = null;
            }

            if ($content === '' || str_starts_with($content, '#') || str_starts_with($content, '---') || str_starts_with($content, '...')) {
                continue;
            }

            // Sequence items, possibly with a mapping key on the same line (`- name: x`).
            while (preg_match('/^-(\s+|$)/', $content, $dash) === 1) {
                while ($stack !== [] && ($stack[array_key_last($stack)]['indent'] > $indent
                    || ($stack[array_key_last($stack)]['item'] && $stack[array_key_last($stack)]['indent'] >= $indent))) {
                    array_pop($stack);
                }

                $parent = array_key_last($stack);
                $index = 0;

                if ($parent !== null) {
                    $index = $stack[$parent]['count'];
                    $stack[$parent]['count']++;
                }

                $stack[] = ['indent' => $indent, 'key' => (string) $index, 'item' => true, 'count' => 0];
                $lines[self::path($stack)] ??= $line;
                $consumed = strlen($dash[0]);
                $indent += $consumed;
                $content = substr($content, $consumed);
            }

            if (preg_match('/^(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\'|([^\s#\'"{\[][^#]*?))\s*:(?:\s+(.*))?$/', $content, $m) !== 1) {
                continue;
            }

            $key = ($m[1] ?? '') !== '' ? stripcslashes($m[1]) : (($m[2] ?? '') !== '' ? str_replace("''", "'", $m[2]) : ($m[3] ?? ''));

            if ($key === '<<') {
                continue;
            }

            while ($stack !== [] && $stack[array_key_last($stack)]['indent'] >= $indent && (! $stack[array_key_last($stack)]['item'] || $stack[array_key_last($stack)]['indent'] >= $indent)) {
                array_pop($stack);
            }

            $stack[] = ['indent' => $indent, 'key' => $key, 'item' => false, 'count' => 0];
            $lines[self::path($stack)] ??= $line;
            $value = trim((string) preg_replace('/\s+#.*$/', '', $m[4] ?? ''));

            if (preg_match('/^[|>][0-9+-]*$/', $value) === 1) {
                $blockIndent = $indent;
            }
        }

        return new self($lines);
    }

    /**
     * The line of $path, else of its nearest indexed parent.
     */
    public function line(string $path): ?int
    {
        $segments = explode('.', $path);

        while ($segments !== []) {
            $candidate = implode('.', $segments);

            if (isset($this->lines[$candidate])) {
                return $this->lines[$candidate];
            }

            array_pop($segments);
        }

        return null;
    }

    /**
     * @param  list<array{indent: int, key: string, item: bool, count: int}>  $stack
     */
    private static function path(array $stack): string
    {
        return implode('.', array_column($stack, 'key'));
    }
}
