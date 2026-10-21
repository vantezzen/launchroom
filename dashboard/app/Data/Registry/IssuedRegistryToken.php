<?php

declare(strict_types=1);

namespace App\Data\Registry;

use Carbon\CarbonImmutable;

/**
 * A freshly signed registry token. The JWT is a bearer credential; it is
 * never logged, persisted or serialized.
 */
final readonly class IssuedRegistryToken
{
    /**
     * @param  list<RegistryScope>  $scopes
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'expiresAt' => $this->expiresAt->toIso8601String()];
    }
}
