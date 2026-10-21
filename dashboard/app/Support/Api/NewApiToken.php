<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\ApiToken;
use LogicException;
use SensitiveParameter;

/**
 * A token just created and its plaintext, which exists only here (docs/16
 * §1.1). Refuses serialization; redacted in dumps.
 */
final readonly class NewApiToken
{
    public function __construct(
        public ApiToken $token,
        #[SensitiveParameter]
        public string $plainText,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token->public_id, 'plainText' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('New API tokens cannot be serialized.');
    }
}
