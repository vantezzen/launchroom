<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Variables\EnvFileEntry;
use App\Data\Variables\EnvFileResult;
use App\Enums\VariableExposure;
use App\Enums\VariableType;

/**
 * Parses pasted `.env` content (docs/06 §3.4). Follows the common dotenv
 * conventions (node dotenv / vlucas phpdotenv) without interpolation:
 *
 * - `KEY=value`, optional `export ` prefix, spaces around `=` allowed;
 * - full-line `#` comments; unquoted values end at ` #` (inline comment) and
 *   are trimmed;
 * - single quotes: literal, may span lines;
 * - double quotes: may span lines; `\n`, `\r`, `\t`, `\"` and `\\` are escapes;
 * - backticks: literal, may span lines;
 * - `$VAR` is never expanded: values are stored verbatim (docs/06 §3.1);
 * - duplicate keys: the last one wins, with a warning.
 *
 * Secret detection: key patterns (VariableType::suggestFor) plus a few
 * unmistakable value shapes (private keys, live API keys, tokens).
 */
final class EnvFileParser
{
    private const string KEY = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    /** Value shapes that are certainly credentials. */
    private const array SECRET_VALUE_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/^(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{10,}$/D',
        '/^gh[pousr]_[A-Za-z0-9]{30,}$/D',
        '/^github_pat_[A-Za-z0-9_]{30,}$/D',
        '/^xox[abpr]-[A-Za-z0-9-]{10,}$/D',
        '/^AKIA[0-9A-Z]{16}$/D',
        '/^[a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:[^\/\s@]+@/i',
    ];

    public static function parse(string $content): EnvFileResult
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }

        $lines = explode("\n", $content);
        $entries = [];
        $errors = [];
        $warnings = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $lineNumber = $i + 1;
            $line = $lines[$i];
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $equals = strpos($trimmed, '=');

            if ($equals === false) {
                $errors[] = ['line' => $lineNumber, 'message' => 'Expected KEY=value.'];

                continue;
            }

            $key = rtrim(substr($trimmed, 0, $equals));
            $rest = ltrim(substr($trimmed, $equals + 1), " \t");

            if (preg_match(self::KEY, $key) !== 1) {
                $errors[] = ['line' => $lineNumber, 'message' => 'Invalid variable name. Use letters, digits and underscores, not starting with a digit.'];

                continue;
            }

            $quote = $rest[0] ?? '';

            if (in_array($quote, ['"', "'", '`'], true)) {
                [$value, $consumed, $closed, $trailing] = self::quoted($quote, substr($rest, 1), $lines, $i);

                if (! $closed) {
                    $errors[] = ['line' => $lineNumber, 'message' => 'Unterminated quoted value.'];

                    break;
                }

                if ($trailing !== '' && ! str_starts_with($trailing, '#')) {
                    $warnings[] = ['line' => $lineNumber, 'message' => 'Ignored text after the closing quote.'];
                }

                $i += $consumed;
            } else {
                $value = self::unquoted($rest);
            }

            if (isset($entries[$key])) {
                $warnings[] = ['line' => $lineNumber, 'message' => "{$key} is defined more than once. The last value is used."];
                unset($entries[$key]);
            }

            $entries[$key] = new EnvFileEntry($key, $value, $lineNumber, self::suggestType($key, $value));
        }

        return new EnvFileResult(array_values($entries), $errors, $warnings);
    }

    public static function suggestType(string $key, string $value): VariableType
    {
        // Browser-inlined keys can never be secrets (docs/06 §3.3).
        if (VariableExposure::isBrowserInlined($key) || VariableType::suggestFor($key) === VariableType::Secret) {
            return VariableType::suggestFor($key);
        }

        return self::looksSecret($value) ? VariableType::Secret : VariableType::Config;
    }

    /**
     * Whether a value has an unmistakable secret shape (private keys, live
     * API keys, GitHub/Slack/AWS tokens, URLs with passwords). launchroom.json
     * refuses such literals (docs/18 §6).
     */
    public static function looksSecret(string $value): bool
    {
        return array_any(self::SECRET_VALUE_PATTERNS, fn (string $pattern): bool => preg_match($pattern, $value) === 1);
    }

    private static function unquoted(string $rest): string
    {
        // An inline comment starts at whitespace followed by '#'.
        if (preg_match('/\s#/', $rest, $m, PREG_OFFSET_CAPTURE) === 1) {
            $rest = substr($rest, 0, $m[0][1]);
        }

        return trim($rest);
    }

    /**
     * @param  list<string>  $lines
     * @return array{string, int, bool, string} value, extra lines consumed, closed, text after the closing quote
     */
    private static function quoted(string $quote, string $first, array $lines, int $index): array
    {
        $buffer = $first;
        $consumed = 0;
        $count = count($lines);

        while (true) {
            $close = self::closingQuote($buffer, $quote);

            if ($close !== null) {
                $raw = substr($buffer, 0, $close);
                $trailing = trim(substr($buffer, $close + 1));

                return [$quote === '"' ? self::unescape($raw) : $raw, $consumed, true, $trailing];
            }

            if ($index + $consumed + 1 >= $count) {
                return ['', $consumed, false, ''];
            }

            $consumed++;
            $buffer .= "\n".$lines[$index + $consumed];
        }
    }

    private static function closingQuote(string $buffer, string $quote): ?int
    {
        $length = strlen($buffer);

        for ($i = 0; $i < $length; $i++) {
            if ($quote === '"' && $buffer[$i] === '\\') {
                $i++;

                continue;
            }

            if ($buffer[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    private static function unescape(string $raw): string
    {
        return (string) preg_replace_callback('/\\\\(.)/s', static fn (array $m): string => match ($m[1]) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '"' => '"',
            '\\' => '\\',
            default => $m[0],
        }, $raw);
    }
}
