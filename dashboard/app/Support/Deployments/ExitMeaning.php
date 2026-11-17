<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Deployments\ContainerExitData;

/**
 * What a container's exit code means, in words (G6, docs/15 §6). launchroomd
 * reports the code, the OOM flag, the signal and whether it stopped the
 * container itself (WorkloadEvent `exitCode`, `oomKilled`, `signal`,
 * `byLaunchroom`). 137 is SIGKILL: out of memory only with the OOM flag,
 * launchroom's own kill after the stop timeout with `byLaunchroom`, else
 * something else killed it (launchroomd can miss the flag when the container
 * was already restarted, so it never guesses).
 */
final class ExitMeaning
{
    private const string GENERIC_ERROR = 'it exited with an error';

    private const string GENERIC_OTHER = 'it reported an error';

    private const array SIGNALS = [
        1 => 'SIGHUP',
        2 => 'SIGINT',
        3 => 'SIGQUIT',
        4 => 'SIGILL',
        6 => 'SIGABRT',
        7 => 'SIGBUS',
        8 => 'SIGFPE',
        9 => 'SIGKILL',
        11 => 'SIGSEGV',
        13 => 'SIGPIPE',
        15 => 'SIGTERM',
    ];

    /**
     * A short phrase ("it ran out of memory"), or null for a clean or
     * unknown exit.
     */
    public static function of(?int $code, bool $oomKilled = false, ?int $signal = null, bool $byLaunchroom = false): ?string
    {
        if ($oomKilled) {
            return 'it ran out of memory';
        }

        if ($code === null || $code === 0) {
            return null;
        }

        $signal = self::signal($code, $signal);

        return match (true) {
            $signal === 9 && $byLaunchroom => 'it was stopped by launchroom after its stop timeout',
            $signal === 9 => 'it was killed (SIGKILL)',
            $signal === 15 && $byLaunchroom => 'it was stopped by launchroom',
            $signal === 15 => 'it was asked to stop (SIGTERM)',
            $signal === 2 => 'it was interrupted (SIGINT)',
            $signal === 11 => 'it crashed (segmentation fault)',
            $signal === 6 => 'it aborted (SIGABRT)',
            $signal === 7 => 'it crashed (bus error)',
            $signal === 4 => 'it crashed (illegal instruction)',
            $signal !== null => 'it was ended by '.(self::SIGNALS[$signal] ?? "signal {$signal}"),
            $code === 127 => 'the start command wasn’t found',
            $code === 126 => 'the start command couldn’t be run',
            $code === 1 => self::GENERIC_ERROR,
            default => self::GENERIC_OTHER,
        };
    }

    /**
     * Like of(), but null when the code says no more than "an error" (1,
     * 2, …): for sentences that already name the code.
     */
    public static function notable(?int $code, bool $oomKilled = false, ?int $signal = null, bool $byLaunchroom = false): ?string
    {
        $meaning = self::of($code, $oomKilled, $signal, $byLaunchroom);

        return in_array($meaning, [self::GENERIC_ERROR, self::GENERIC_OTHER], true) ? null : $meaning;
    }

    /**
     * "Exited with code 137: it ran out of memory.", "Exited with code 0."
     */
    public static function summary(?int $code, bool $oomKilled = false, ?int $signal = null, bool $byLaunchroom = false): string
    {
        $meaning = self::of($code, $oomKilled, $signal, $byLaunchroom);
        $head = $code === null ? 'Exited' : "Exited with code {$code}";

        if ($meaning === null) {
            return $head.'.';
        }

        return $head.': '.$meaning.'.';
    }

    /**
     * What to do about it, when the code alone says enough.
     */
    public static function advice(?int $code, bool $oomKilled = false, ?int $signal = null, bool $byLaunchroom = false): ?string
    {
        if ($oomKilled) {
            return 'Raise its memory limit in Settings → Scaling, or reduce what the app keeps in memory.';
        }

        if ($code === null || $code === 0) {
            return null;
        }

        $signal = self::signal($code, $signal);

        return match (true) {
            $signal === 9 && $byLaunchroom => 'The app didn’t finish within its stop timeout. Raise Stop timeout in Settings → Deploy if it needs longer to shut down.',
            $signal === 9 => 'launchroom couldn’t confirm it ran out of memory. Check the server’s memory, and the last lines for what happened.',
            $code === 127 => 'Check the start command in Settings → Deploy, and that the program is installed in the image.',
            $code === 126 => 'Make the start file executable (chmod +x in the Dockerfile), or start it through its interpreter, for example sh start.sh.',
            default => null,
        };
    }

    public static function ofExit(ContainerExitData $exit): ?string
    {
        return self::of($exit->code, $exit->oomKilled, $exit->signal, $exit->byLaunchroom);
    }

    public static function notableOfExit(ContainerExitData $exit): ?string
    {
        return self::notable($exit->code, $exit->oomKilled, $exit->signal, $exit->byLaunchroom);
    }

    public static function summaryOfExit(ContainerExitData $exit): string
    {
        return self::summary($exit->code, $exit->oomKilled, $exit->signal, $exit->byLaunchroom);
    }

    public static function signalName(int $signal): string
    {
        return self::SIGNALS[$signal] ?? "signal {$signal}";
    }

    /**
     * The signal that ended the process: launchroomd's, else code − 128.
     */
    private static function signal(int $code, ?int $signal): ?int
    {
        if ($signal !== null && $signal > 0) {
            return $signal;
        }

        return $code > 128 && $code <= 128 + 64 ? $code - 128 : null;
    }
}
