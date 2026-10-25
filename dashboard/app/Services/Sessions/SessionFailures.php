<?php

declare(strict_types=1);

namespace App\Services\Sessions;

/**
 * Turns why launchroomd couldn't start a session (the session task's error,
 * docs/03 §11.4) into one sentence for the Console and Data tabs. The raw
 * error stays in the task and the log.
 */
final class SessionFailures
{
    private const int MAX = 300;

    public static function sentence(?string $error): ?string
    {
        $error = trim((string) $error);

        if ($error === '') {
            return null;
        }

        $lower = mb_strtolower($error);

        return match (true) {
            str_contains($lower, 'this image has no shell') => 'This image has no shell (looked for bash, ash and sh), so there is no console for it.',
            (bool) preg_match('~this image has no (/bin/\w+)~', $error, $shell) => "This image has no {$shell[1]}. Pick Automatic or another shell.",
            str_contains($lower, "couldn't start in the container") => 'The shell couldn’t start in this container: '.self::clip($error),
            str_contains($lower, 'sessions are turned off on this server') => 'The server refused: sessions of this kind are turned off there (LR_INTERACTIVE_SESSIONS).',
            str_contains($lower, 'dockerd-guard') => 'The server refused to open it (dockerd-guard): '.self::clip(self::afterLast($error, 'dockerd-guard')),
            str_contains($lower, "refused by this server's policy") => 'The server refused: '.self::clip($error),
            str_contains($lower, 'is not running') => 'The container isn’t running right now. Try again once it is back.',
            str_contains($lower, 'relay') || str_contains($lower, 'realtime') => 'The server couldn’t reach the live connection (Reverb): '.self::clip($error),
            str_contains($lower, 'session key') => 'The server couldn’t open the session key. Update it in Settings → Updates.',
            default => 'The server couldn’t start the session: '.self::clip($error),
        };
    }

    private static function afterLast(string $error, string $marker): string
    {
        $at = mb_strripos($error, $marker);

        return $at === false ? $error : ltrim(mb_substr($error, $at + mb_strlen($marker)), " :\t");
    }

    private static function clip(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_strlen($text) > self::MAX ? mb_substr($text, 0, self::MAX - 1).'…' : $text;
    }
}
