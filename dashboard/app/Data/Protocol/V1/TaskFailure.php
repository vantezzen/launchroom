<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Attributes\UnknownCodeAsNull;
use App\Enums\TaskFailureClass;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Classifies a failed build or plan task (protocol TaskFailure), `step` is the
 * failing build step. `class` is an unknown-tolerant code (docs/03 §10): it
 * is kept as sent and an unknown one reads as InternalError, so a completion
 * from a newer launchroomd is still recorded.
 */
final class TaskFailure extends Data
{
    /**
     * @param  list<BuildWarning>|null  $hints
     */
    public function __construct(
        #[Regex(UnknownCodeAsNull::GRAMMAR)]
        public string $class,
        public bool $retryable = false,
        #[Max(128)]
        public ?string $step = null,
        public ?array $hints = null,
        public ?BuildSummary $summary = null,
    ) {}

    public static function of(TaskFailureClass $class, string $step): self
    {
        return new self($class->value, $class->retryable(), $step);
    }

    /**
     * An unknown class counts as a daemon-side problem.
     */
    public function failureClass(): TaskFailureClass
    {
        return TaskFailureClass::tryFrom($this->class) ?? TaskFailureClass::InternalError;
    }

    public function shouldRetry(): bool
    {
        return $this->retryable || $this->failureClass()->retryable();
    }
}
