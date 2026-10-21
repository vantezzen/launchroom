<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentFailureClass;

/**
 * Result of advancing an external deployment step (build, release command):
 * still running, done, or failed with a classified, user-facing reason.
 */
final readonly class StepOutcome
{
    private function __construct(
        public string $state,
        public ?DeploymentFailureClass $failureClass = null,
        public ?string $message = null,
    ) {}

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function succeeded(?string $message = null): self
    {
        return new self('succeeded', message: $message);
    }

    public static function failed(DeploymentFailureClass $class, string $message): self
    {
        return new self('failed', $class, $message);
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->state === 'failed';
    }
}
