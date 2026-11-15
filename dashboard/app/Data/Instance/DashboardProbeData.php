<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * Outcome of the HTTPS test request to a new dashboard domain (docs/05
 * §4.1). `via` is the address it connected to (the main server's public
 * address, or the configured in-network fallback).
 */
final class DashboardProbeData extends Data
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $via,
        public string $checkedAt,
    ) {}

    public static function passed(string $via): self
    {
        return new self(true, 'This dashboard answered over HTTPS with a valid certificate.', $via, now()->toIso8601ZuluString());
    }

    public static function failed(string $message, ?string $via = null): self
    {
        return new self(false, $message, $via, now()->toIso8601ZuluString());
    }
}
