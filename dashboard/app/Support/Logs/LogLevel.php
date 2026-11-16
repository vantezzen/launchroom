<?php

declare(strict_types=1);

namespace App\Support\Logs;

use App\Enums\LogLineLevel;

/**
 * The level of a log line, read from its text (lines carry no level): the
 * level a structured line declares (a JSON `level`/`severity`, logfmt
 * `level=`, Monolog's `[date] channel.LEVEL:`), else error keywords,
 * warnings, debug, else info. Stored with each line at ingest
 * (`lines[].level`) so search and histograms can filter on it; the migration
 * that introduced it ported the keyword rules to Postgres regexes for older
 * lines.
 */
final class LogLevel
{
    /** Level words of common loggers (Monolog/PSR-3, Go slog/zap, Python, pino names). */
    private const array WORDS = [
        'trace' => 'debug', 'debug' => 'debug', 'dbg' => 'debug',
        'info' => 'info', 'information' => 'info', 'notice' => 'info',
        'warn' => 'warning', 'warning' => 'warning',
        'error' => 'error', 'err' => 'error', 'critical' => 'error', 'crit' => 'error', 'alert' => 'error',
        'emergency' => 'error', 'emerg' => 'error', 'fatal' => 'error', 'panic' => 'error', 'dpanic' => 'error',
    ];

    public static function guess(string $text): string
    {
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $text);

        return self::declared($plain) ?? match (true) {
            (bool) preg_match('/\b(error|err!|fatal|panic|exception|traceback|failed)\b|ERR_|SQLSTATE|exit code: [1-9]/i', $plain) => 'error',
            (bool) preg_match('/\b(warn|warning|deprecated)\b/i', $plain) => 'warning',
            (bool) preg_match('/^\s*(\[?debug\]?|dbg)\b|\blevel=debug\b/i', $plain) => 'debug',
            default => 'info',
        };
    }

    /**
     * The level a structured line states about itself, null when it states none.
     */
    private static function declared(string $plain): ?string
    {
        $trimmed = ltrim($plain);

        if (str_starts_with($trimmed, '{')) {
            $json = json_decode($trimmed, true);

            if (is_array($json)) {
                foreach (['level', 'severity', 'lvl', 'log.level', 'levelname'] as $key) {
                    $value = $json[$key] ?? null;

                    if (is_string($value) && isset(self::WORDS[strtolower($value)])) {
                        return self::WORDS[strtolower($value)];
                    }

                    // pino and bunyan: 10 trace, 20 debug, 30 info, 40 warn, 50 error, 60 fatal.
                    if (is_int($value) && $value >= 10 && $value <= 60) {
                        return match (true) {
                            $value < 30 => 'debug',
                            $value < 40 => 'info',
                            $value < 50 => 'warning',
                            default => 'error',
                        };
                    }
                }
            }
        }

        // Monolog's line format (Laravel, Symfony): "[2026-11-16 10:00:00] production.ERROR: …".
        if (preg_match('/^\[[^\]]{8,40}\] [\w.-]+\.([A-Z]+):/', $trimmed, $m) === 1) {
            return self::WORDS[strtolower($m[1])] ?? null;
        }

        if (preg_match('/(?:^|\s)(?:level|lvl)="?([a-zA-Z]+)"?(?:\s|$)/', $trimmed, $m) === 1) {
            return self::WORDS[strtolower($m[1])] ?? null;
        }

        return null;
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

        if (is_string($level) && LogLineLevel::tryFrom($level) instanceof LogLineLevel) {
            return $level;
        }

        return self::guess(is_string($line['text'] ?? null) ? $line['text'] : '');
    }
}
