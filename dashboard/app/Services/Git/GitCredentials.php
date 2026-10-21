<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Support\Git\HostKey;
use LogicException;
use SensitiveParameter;

/**
 * What a build needs to clone one repository (docs/19 §6): the URL (never
 * with credentials in it) and either an HTTP token (sealed as TaskToken
 * purpose `clone`), or an ed25519 deploy key seed plus the pinned host keys
 * (purpose `ssh_key`), or nothing (public remotes). Never serializable or
 * printed.
 */
final readonly class GitCredentials
{
    /**
     * @param  list<HostKey>  $knownHosts
     */
    public function __construct(
        public string $cloneUrl,
        #[SensitiveParameter] public ?string $token = null,
        #[SensitiveParameter] public ?string $sshKeySeed = null,
        public array $knownHosts = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['cloneUrl' => $this->cloneUrl, 'token' => $this->token === null ? null : '[redacted]', 'sshKeySeed' => $this->sshKeySeed === null ? null : '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Git credentials cannot be serialized.');
    }
}
