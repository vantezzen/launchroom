<?php

declare(strict_types=1);

namespace App\Support\Deployments\Failures;

use App\Enums\DeploymentFailureClass;
use App\Enums\WorkloadFailureCode;

/**
 * What is known about a failed deployment when explaining it.
 */
final readonly class FailureContext
{
    /**
     * @param  list<string>  $hintCodes  known-error codes launchroomd attached: build hints (`pnpm-outdated-lockfile`), the port hint of the failed replica (PortHint), its probe hint (`host_not_allowed`, ProbeHint) and its typed start failure (WorkloadFailureCode)
     * @param  list<int>  $listening  ports the failed replica listened on (with a port hint)
     */
    public function __construct(
        public DeploymentFailureClass $class,
        public ?string $message = null,
        public array $hintCodes = [],
        /** A few log lines around the first error, when cheaply available. */
        public ?string $logExcerpt = null,
        public ?int $currentPort = null,
        public ?int $healthStartPeriodSeconds = null,
        public array $listening = [],
        /** The port the port hint was checked against. */
        public ?int $hintPort = null,
        /** The failed replica's last exit (WorkloadEvent `exited`). */
        public ?int $exitCode = null,
        public bool $oomKilled = false,
        public ?int $signal = null,
        public bool $byLaunchroom = false,
        /** The server the failure happened on (name, for the copy). */
        public ?string $server = null,
        /** Its public id (`free_disk`). */
        public ?string $serverId = null,
        /** The image's user from launchroomd's image facts ("1000", "app", "" = root). */
        public ?string $imageUser = null,
        /** The first bytes of the failing health check's answer, masked. */
        public ?string $probeBody = null,
    ) {}

    public function text(): string
    {
        return mb_substr(trim(($this->message ?? '')."\n".($this->logExcerpt ?? '')."\n".($this->probeBody ?? '')), 0, 20000);
    }

    public function hasHint(string $code): bool
    {
        return in_array($code, $this->hintCodes, true);
    }

    public function failureCode(): ?WorkloadFailureCode
    {
        foreach ($this->hintCodes as $code) {
            $failure = WorkloadFailureCode::tryFrom($code);

            if ($failure instanceof WorkloadFailureCode) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * The image's numeric user id when it runs as one that isn't root
     * (launchroom can then give a new volume to it, `initOwner`).
     */
    public function numericImageUser(): ?int
    {
        if ($this->imageUser === null || preg_match('/^(\d{1,10})(?::\d{1,10})?$/D', $this->imageUser, $m) !== 1) {
            return null;
        }

        $uid = (int) $m[1];

        return $uid === 0 ? null : $uid;
    }

    /** "server-2", else "The server" / "the server". */
    public function serverName(bool $capital = true): string
    {
        return $this->server ?? ($capital ? 'The server' : 'the server');
    }
}
