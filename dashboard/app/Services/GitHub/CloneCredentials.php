<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use LogicException;
use SensitiveParameter;

/**
 * What a build needs to clone one repository: the plain HTTPS URL (no
 * credentials in it) and a repository-scoped installation token to send as
 * `Authorization: Basic base64("x-access-token:<token>")` via git's
 * `http.extraHeader` (docs/04 "Clone"). Never serializable or printed.
 */
final readonly class CloneCredentials
{
    public const string USERNAME = 'x-access-token';

    public function __construct(
        public string $cloneUrl,
        public int $repositoryId,
        #[SensitiveParameter] public InstallationToken $token,
    ) {}

    /**
     * Value for `http.extraHeader`.
     */
    public function authorizationHeader(): string
    {
        return 'Authorization: Basic '.base64_encode(self::USERNAME.':'.$this->token->reveal());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['cloneUrl' => $this->cloneUrl, 'token' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Clone credentials cannot be serialized.');
    }
}
