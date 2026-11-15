<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

/**
 * The interactive session whose key a task gets (`seal.sessionKey`, docs/03 §11.1).
 */
final class SessionKeySeal extends Data
{
    public function __construct(
        public string $session,
    ) {}
}
