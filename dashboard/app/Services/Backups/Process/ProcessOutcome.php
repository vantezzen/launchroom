<?php

declare(strict_types=1);

namespace App\Services\Backups\Process;

use Symfony\Component\Process\Process;

/**
 * Exit code and output of a finished process, with secrets masked in
 * stderr (docs/09 R16). stdout is kept raw for JSON parsing and never
 * surfaced to users or logs.
 */
final readonly class ProcessOutcome
{
    private const int MAX_STDERR = 4096;

    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * @param  list<string>  $secrets
     */
    public static function from(Process $process, array $secrets): self
    {
        $stderr = $process->isStarted() ? $process->getErrorOutput() : '';

        return new self(
            $process->getExitCode() ?? -1,
            $process->isStarted() ? $process->getOutput() : '',
            self::mask(substr($stderr, -self::MAX_STDERR), $secrets),
        );
    }

    /**
     * Replaces secret values (raw, base64 and URL-encoded) with "***".
     *
     * @param  list<string>  $secrets
     */
    public static function mask(string $text, array $secrets): string
    {
        $needles = [];

        foreach ($secrets as $secret) {
            if (strlen($secret) < 4) {
                continue;
            }

            $needles[] = $secret;
            $needles[] = base64_encode($secret);
            $needles[] = rawurlencode($secret);
        }

        $needles = array_values(array_unique($needles));
        usort($needles, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $needles === [] ? $text : str_replace($needles, '***', $text);
    }

    /**
     * The last non-empty stderr line (restic's "Fatal: …"), for messages.
     */
    public function lastErrorLine(): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $this->stderr)), static fn (string $l): bool => $l !== ''));

        return $lines === [] ? '' : mb_substr($lines[count($lines) - 1], 0, 500);
    }
}
