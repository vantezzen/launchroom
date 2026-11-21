<?php

declare(strict_types=1);

namespace App\Support\Logs;

/**
 * A log line without its terminal escapes (colours, cursor moves), for
 * plain-text output: downloads and the API's failure excerpt.
 */
final class AnsiText
{
    public static function strip(string $text): string
    {
        return str_contains($text, "\e") ? (preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $text) ?? $text) : $text;
    }
}
