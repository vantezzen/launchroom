<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Outcome of an {@see EdgeReachability} check: which server answered, or
 * why none did (one secret-free clause, shown after "couldn't confirm: ").
 */
final readonly class ReachabilityResult
{
    private function __construct(
        public bool $reached,
        public ?string $serverId,
        public ?string $serverName,
        public ?string $error,
    ) {}

    public static function reached(string $serverId, string $serverName): self
    {
        return new self(true, $serverId, $serverName, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
