<?php

declare(strict_types=1);

namespace App\Services\Http;

/**
 * A URL that passed {@see UrlGuard}, together with the single IP it must be
 * fetched from.
 */
final readonly class ResolvedTarget
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /**
     * Entry for CURLOPT_RESOLVE so curl connects to the vetted IP instead of
     * resolving the host again (prevents DNS rebinding between check and use).
     */
    public function curlResolveEntry(): string
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
