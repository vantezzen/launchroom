<?php

declare(strict_types=1);

namespace App\Support\Logs;

/**
 * The level of a log line, guessed from its text (lines carry no level):
 * error keywords, warnings, debug, else info. Stored with each line at
 * ingest (`lines[].level`) so search and histograms can filter on it; the
 * migration that introduced it ported the same rules to Postgres regexes
 * for older lines.
 */
final class LogLevel
{
    public const array LEVELS = ['error', 'warning', 'info', 'debug'];

    public static function guess(string $text): string
    {
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $text);

        return match (true) {
            (bool) preg_match('/\b(error|err!|fatal|panic|exception|traceback|failed)\b|ERR_|SQLSTATE|exit code: [1-9]/i', $plain) => 'error',
            (bool) preg_match('/\b(warn|warning|deprecated)\b/i', $plain) => 'warning',
            (bool) preg_match('/^\s*(\[?debug\]?|dbg)\b|\blevel=debug\b/i', $plain) => 'debug',
            default => 'info',
        };
    }

    /**
     * The stored level of a line, else the guess (lines from before levels
     * were stored).
     *
     * @param  array<string, mixed>  $line
     */
    public static function of(array $line): string
    {
        $level = $line['level'] ?? null;

        if (is_string($level) && in_array($level, self::LEVELS, true)) {
            return $level;
        }

        return self::guess(is_string($line['text'] ?? null) ? $line['text'] : '');
    }
}
