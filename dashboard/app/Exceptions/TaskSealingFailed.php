<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\TaskFailureClass;
use RuntimeException;

/**
 * A task's secrets could not be sealed for the leasing server (clone token
 * not mintable, env snapshot missing). The task fails with this user-facing
 * message instead of being handed out.
 */
final class TaskSealingFailed extends RuntimeException
{
    public function __construct(string $message, public readonly TaskFailureClass $failureClass = TaskFailureClass::ConfigError)
    {
        parent::__construct($message);
    }
}
