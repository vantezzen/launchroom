<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Support\Images\RegistryLayout;
use SensitiveParameter;

/**
 * Sign-in details for one upstream registry during one copy or check, plus
 * the bearer tokens the registry handed out for it (per scope). Never
 * serialized or logged.
 */
final class UpstreamAuth
{
    /** @var array<string, string> scope => bearer token */
    private array $tokens = [];

    private bool $basic = false;

    public function __construct(
        public readonly string $host,
        public readonly string $username,
        #[SensitiveParameter]
        private readonly string $secret,
    ) {}

    /**
     * No sign-in details: public images (tag lists and manifests of Docker
     * Hub and GHCR still need an anonymous token from the registry's realm).
     */
    public static function anonymous(string $host): self
    {
        return new self($host, '', '');
    }

    public function isAnonymous(): bool
    {
        return $this->username === '' && $this->secret === '';
    }

    /**
     * The registry's API origin: Docker Hub's API is not at docker.io.
     */
    public function baseUrl(): string
    {
        return RegistryLayout::MIRRORED[$this->host] ?? 'https://'.$this->host;
    }

    public function basicHeader(): string
    {
        return 'Basic '.base64_encode($this->username.':'.$this->secret);
    }

    public function useBasic(): void
    {
        $this->basic = true;
    }

    public function remember(string $scope, #[SensitiveParameter] string $token): void
    {
        $this->tokens[$scope] = $token;
    }

    public function authorization(string $scope): ?string
    {
        return match (true) {
            $this->basic && ! $this->isAnonymous() => $this->basicHeader(),
            isset($this->tokens[$scope]) => 'Bearer '.$this->tokens[$scope],
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'username' => $this->username, 'secret' => '[redacted]'];
    }
}
