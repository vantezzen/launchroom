<?php

declare(strict_types=1);

namespace App\Services\Backups\Process;

use RuntimeException;

/**
 * A backup process failed. Messages are secret-free (masked) and safe to
 * show to admins; they never include argv or environment.
 */
final class ProcessFailed extends RuntimeException
{
    public static function restic(string $doing, ProcessOutcome $outcome): self
    {
        $hint = match ($outcome->exitCode) {
            ResticCli::EXIT_REPOSITORY_MISSING => 'the repository does not exist',
            ResticCli::EXIT_LOCKED => 'the repository is locked by another operation; try again later',
            ResticCli::EXIT_WRONG_PASSWORD => 'the repository password is wrong',
            default => $outcome->lastErrorLine(),
        };

        return new self("restic could not {$doing} (exit {$outcome->exitCode})".($hint === '' ? '.' : ": {$hint}"));
    }

    public static function producer(string $doing, ProcessOutcome $outcome): self
    {
        $line = $outcome->lastErrorLine();

        return new self("Could not {$doing} (exit {$outcome->exitCode})".($line === '' ? '.' : ": {$line}"));
    }

    public static function message(string $message): self
    {
        return new self($message);
    }
}
