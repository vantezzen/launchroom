<?php

declare(strict_types=1);

namespace App\Support\Compose;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a Compose file's YAML with hard limits (docs/17 §2.2): no PHP
 * objects, constants or custom tags, ≤ 256 KiB, ≤ 64 aliases, ≤ 20 000
 * nodes after alias expansion (so a "billion laughs" file stops early)
 * and nesting ≤ 32. Duplicate keys are a parse error.
 */
final class ComposeYaml
{
    public const int MAX_BYTES = 262144;

    public const int MAX_ALIASES = 64;

    public const int MAX_NODES = 20000;

    public const int MAX_DEPTH = 32;

    /**
     * @return array<array-key, mixed>
     *
     * @throws ComposeRejected
     */
    public static function parse(string $yaml): array
    {
        if (strlen($yaml) > self::MAX_BYTES) {
            throw new ComposeRejected('The file is larger than 256 KB.');
        }

        if (trim($yaml) === '') {
            throw new ComposeRejected('The file is empty.');
        }

        if (! mb_check_encoding($yaml, 'UTF-8')) {
            throw new ComposeRejected('The file isn’t UTF-8 text.');
        }

        if (preg_match_all('/(?:^|[\s\[{,:-])\*[^\s,\]}]+/m', $yaml) > self::MAX_ALIASES) {
            throw new ComposeRejected('The file uses more than '.self::MAX_ALIASES.' YAML aliases.');
        }

        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $line = $e->getParsedLine();

            $reason = rtrim((string) preg_replace('/ at line \d+.*$/s', '', $e->getMessage()), '.');

            throw new ComposeRejected('This isn’t valid YAML'.($line > 0 ? " (line {$line})" : '').': '.$reason.'.', $line > 0 ? $line : null);
        }

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw new ComposeRejected('A Compose file is a mapping with a services section.');
        }

        $nodes = 0;
        self::measure($data, 1, $nodes);

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $node
     *
     * @throws ComposeRejected
     */
    private static function measure(array $node, int $depth, int &$nodes): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ComposeRejected('The file is nested more than '.self::MAX_DEPTH.' levels deep.');
        }

        foreach ($node as $value) {
            if (++$nodes > self::MAX_NODES) {
                throw new ComposeRejected('The file expands to more than '.self::MAX_NODES.' values.');
            }

            if (is_array($value)) {
                self::measure($value, $depth + 1, $nodes);
            }
        }
    }
}
