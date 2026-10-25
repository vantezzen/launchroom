<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * One replica of an investigation in progress (docs/22 §9.4).
 */
final class InvestigationReplicaData extends Data
{
    public function __construct(
        public string $server,
        /** `pending`, `done`, `failed`, `offline` or `unsupported`. */
        public string $state,
        public ?string $message,
    ) {}
}
