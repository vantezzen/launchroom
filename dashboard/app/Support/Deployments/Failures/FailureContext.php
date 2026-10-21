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
     * @param  list<string>  $hintCodes  known-error codes launchroomd attached to the failed build (e.g. `pnpm-outdated-lockfile`)
     */
    public function __construct(
        public DeploymentFailureClass $class,
        public ?string $message = null,
        public array $hintCodes = [],
        /** A few log lines around the first error, when cheaply available. */
        public ?string $logExcerpt = null,
        public ?int $currentPort = null,
        public ?int $healthStartPeriodSeconds = null,
    ) {}

    public function text(): string
    {
        return mb_substr(trim(($this->message ?? '')."\n".($this->logExcerpt ?? '')), 0, 20000);
    }
}
