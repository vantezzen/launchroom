<?php

declare(strict_types=1);

namespace App\Support\Deployments\Failures;

use App\Enums\DeploymentFailureClass;

/**
 * What is known about a failed deployment when explaining it.
 */
final readonly class FailureContext
{
    /**
     * @param  list<string>  $hintCodes  known-error codes launchroomd attached: build hints (`pnpm-outdated-lockfile`) and the port hint of the failed replica (PortHint)
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
    ) {}

    public function text(): string
    {
        return mb_substr(trim(($this->message ?? '')."\n".($this->logExcerpt ?? '')), 0, 20000);
    }
}
