<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * An edge that serves routes without the firewall (docs/22 §6.6).
 */
final class WafEdgeProblemData extends Data
{
    public function __construct(
        public string $server,
        public string $serverId,
        /** `error` or `unsupported`. */
        public string $state,
        public ?string $message,
    ) {}
}
