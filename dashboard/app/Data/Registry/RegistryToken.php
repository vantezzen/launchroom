<?php

declare(strict_types=1);

namespace App\Data\Registry;

use App\Data\Protocol\ProtocolDocument;
use App\Data\Protocol\ProtocolJson;
use Carbon\CarbonImmutable;

/**
 * Signed response to POST /daemon/v1/registry-token
 * (protocol/schema/v1/registry-token.json). The token is a credential: this
 * object is only ever encoded into that one response.
 */
final readonly class RegistryToken implements ProtocolDocument
{
    /**
     * @param  list<RegistryScope>  $scopes
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $token,
        public CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}

    public function toProtocol(): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'RegistryToken',
            'token' => $this->token,
            'expiresAt' => ProtocolJson::time($this->expiresAt),
            'scopes' => array_map(static fn (RegistryScope $scope): array => $scope->toProtocol(), $this->scopes),
        ];
    }
}
