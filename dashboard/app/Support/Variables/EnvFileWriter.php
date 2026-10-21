<?php

declare(strict_types=1);

namespace App\Support\Variables;

use SensitiveParameter;

/**
 * Writes `KEY=value` lines that App\Support\Variables\EnvFileParser reads
 * back verbatim: simple values unquoted, everything else double-quoted with
 * the parser's escapes (`\\`, `\"`, `\n`, `\r`, `\t`).
 */
final class EnvFileWriter
{
    public static function line(string $key, #[SensitiveParameter] string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.,:\/@+=%{}$-]*$/D', $value) === 1 && ! str_contains($value, '${{')) {
            return "{$key}={$value}";
        }

        return $key.'="'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).'"';
    }
}
