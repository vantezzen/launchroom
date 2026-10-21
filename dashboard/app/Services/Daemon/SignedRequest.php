<?php

declare(strict_types=1);

namespace App\Services\Daemon;

/**
 * The parts of a daemon request that the RFC 9421 profile covers
 * (protocol/SIGNING.md §2), taken from the raw request.
 */
final readonly class SignedRequest
{
    /**
     * @param  array<string, list<string>>  $headers  lowercase name => values (every occurrence)
     * @param  string  $requestTarget  raw request target as sent (`$_SERVER['REQUEST_URI']`)
     */
    public function __construct(
        public string $method,
        public string $requestTarget,
        public array $headers,
        public string $body,
    ) {}

    public function path(): string
    {
        $path = explode('?', $this->requestTarget, 2)[0];

        return $path === '' ? '/' : $path;
    }

    /**
     * `?` plus the raw query, or just `?` (RFC 9421 §2.2.7).
     */
    public function query(): string
    {
        $parts = explode('?', $this->requestTarget, 2);

        return '?'.($parts[1] ?? '');
    }
}
