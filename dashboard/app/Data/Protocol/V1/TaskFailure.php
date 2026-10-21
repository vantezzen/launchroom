<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Classifies a failed build or plan task (protocol TaskFailure): `class` is a
 * protocol FailureClass (plan_error, build_error, transient, clone_error,
 * config_error, timeout, internal_error; later task types may add more, so it
 * is validated by grammar), `step` the failing build step.
 */
final class TaskFailure extends Data
{
    /**
     * @param  list<BuildWarning>|null  $hints
     */
    public function __construct(
        #[Regex('/^[a-z][a-z0-9_]{0,31}$/D')]
        public string $class,
        public bool $retryable = false,
        #[Max(128)]
        public ?string $step = null,
        public ?array $hints = null,
        public ?BuildSummary $summary = null,
    ) {}
}
