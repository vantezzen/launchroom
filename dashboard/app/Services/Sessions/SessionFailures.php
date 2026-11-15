<?php

declare(strict_types=1);

namespace App\Services\Sessions;

use App\Enums\Sessions\StartFailure;

/**
 * Turns why launchroomd couldn't start a session (the failure code of its
 * SessionResult, docs/03 §11.4) into one sentence for the Console and Data
 * tabs. The raw error stays in the task and the log; it is quoted where it
 * says more than the code. A daemon that sends no code (older than the
 * codes) gets the generic sentence with its error.
 */
final class SessionFailures
{
    private const int MAX = 300;

    public static function sentence(?StartFailure $failure, ?string $error, ?string $shell = null): ?string
    {
        $error = trim((string) $error);

        if (! $failure instanceof StartFailure && $error === '') {
            return null;
        }

        return match ($failure) {
            StartFailure::NoShell => 'This image has no shell (looked for bash, ash and sh), so there is no console for it.',
            StartFailure::ShellMissing => 'This image has no '.($shell === null || $shell === '' ? 'such shell' : $shell).'. Pick Automatic or another shell.',
            StartFailure::ShellStartFailed => 'The shell couldn’t start in this container: '.self::clip($error),
            StartFailure::KindDisabled => 'The server refused: sessions of this kind are turned off there (LR_INTERACTIVE_SESSIONS).',
            StartFailure::GuardRefused => 'The server refused to open it (dockerd-guard): '.self::clip(self::afterLast($error, 'dockerd-guard')),
            StartFailure::PolicyRefused => 'The server refused: '.self::clip($error),
            StartFailure::NotRunning => 'The container isn’t running right now. Try again once it is back.',
            StartFailure::RelayUnreachable => 'The server couldn’t reach the live connection (Reverb): '.self::clip($error),
            StartFailure::SessionKey => 'The server couldn’t open the session key. Update it in Settings → Updates.',
            null => 'The server couldn’t start the session: '.self::clip($error),
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
