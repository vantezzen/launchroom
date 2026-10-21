<?php

declare(strict_types=1);

namespace App\Support\Compose;

use InvalidArgumentException;

/**
 * Splits a Compose string command into argv the way Compose itself does
 * (word splitting with single quotes, double quotes and backslashes) and
 * nothing more: no expansion, globbing, substitution or operators, and it
 * never reaches a shell (docs/09 R1, docs/17 §2.2). Unquoted shell syntax
 * (`&&`, `|`, `;`, `>`, `$(`, backticks, `$VAR`) is refused: without a
 * shell it would be passed on as plain words, which is never what the
 * author meant.
 */
final class CommandLine
{
    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function split(string $command): array
    {
        [$words, $operators] = self::scan($command);

        if ($operators !== []) {
            throw new InvalidArgumentException('It uses shell syntax ('.implode(' ', array_unique($operators)).'), which runs without a shell here. Write it as a list, e.g. ["sh", "-c", "…"].');
        }

        return $words;
    }

    /**
     * Words up to the first shell operator (`curl -f http://x || exit 1` →
     * `curl -f http://x`), for reading what a CMD-SHELL health check tests.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException on unbalanced quotes
     */
    public static function leadingWords(string $command): array
    {
        return self::scan($command, stopAtOperator: true)[0];
    }

    /**
     * @return array{list<string>, list<string>} words and the operators found
     *
     * @throws InvalidArgumentException
     */
    private static function scan(string $command, bool $stopAtOperator = false): array
    {
        $words = [];
        $operators = [];
        $word = '';
        $inWord = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($char === "'") {
                $end = strpos($command, "'", $i + 1);

                if ($end === false) {
                    throw new InvalidArgumentException('A single quote is never closed.');
                }

                $word .= substr($command, $i + 1, $end - $i - 1);
                $inWord = true;
                $i = $end;

                continue;
            }

            if ($char === '"') {
                $i++;
                $closed = false;

                for (; $i < $length; $i++) {
                    $c = $command[$i];

                    if ($c === '\\' && $i + 1 < $length && in_array($command[$i + 1], ['"', '\\', '$', '`', "\n"], true)) {
                        $word .= $command[$i + 1] === "\n" ? '' : $command[$i + 1];
                        $i++;

                        continue;
                    }

                    if ($c === '"') {
                        $closed = true;
                        break;
                    }

                    if ($c === '`' || ($c === '$' && preg_match('/\G\$[A-Za-z_{(]/', $command, $unused, 0, $i) === 1)) {
                        $operators[] = $c === '`' ? '`' : '$';
                    }

                    $word .= $c;
                }

                if (! $closed) {
                    throw new InvalidArgumentException('A double quote is never closed.');
                }

                $inWord = true;

                continue;
            }

            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $word .= $command[$i + 1] === "\n" ? '' : $command[$i + 1];
                    $inWord = $inWord || $command[$i + 1] !== "\n";
                    $i++;
                }

                continue;
            }

            if (ctype_space($char)) {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }

                continue;
            }

            $operator = self::operatorAt($command, $i);

            if ($operator !== null) {
                if ($stopAtOperator) {
                    break;
                }

                $operators[] = $operator;
            }

            $word .= $char;
            $inWord = true;
        }

        if ($inWord && (! $stopAtOperator || $word !== '')) {
            $words[] = $word;
        }

        return [$words, $operators];
    }

    private static function operatorAt(string $command, int $i): ?string
    {
        $char = $command[$i];

        return match (true) {
            in_array(substr($command, $i, 2), ['&&', '||', '$('], true) => substr($command, $i, 2),
            in_array($char, ['|', '&', ';', '<', '>', '`', '(', ')'], true) => $char,
            $char === '$' && preg_match('/\G\$[A-Za-z_{]/', $command, $unused, 0, $i) === 1 => '$',
            default => null,
        };
    }
}
