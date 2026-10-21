<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Carbon\CarbonImmutable;
use LogicException;
use SensitiveParameter;

/**
 * A short-lived GitHub App installation token (docs/09 R19). Never
 * serializable, never printed; read the value with {@see reveal()} only where
 * it is sent to GitHub or sealed for a daemon.
 */
final readonly class InstallationToken
{
    /**
     * @param  list<int>|null  $repositoryIds  null = every repository of the installation
     * @param  array<string, string>  $permissions
     */
    public function __construct(
        #[SensitiveParameter] private string $token,
        public CarbonImmutable $expiresAt,
        public ?array $repositoryIds,
        public array $permissions,
    ) {}

    public function reveal(): string
    {
        return $this->token;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'expiresAt' => $this->expiresAt->toIso8601String()];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Installation tokens cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Installation tokens cannot be unserialized.');
    }
}
