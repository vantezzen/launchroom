<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Models\ApiToken;
use Spatie\LaravelData\Data;

/**
 * A token as its owner sees it (never its secret): `GET /me`, Settings → API
 * tokens.
 */
final class ApiTokenData extends Data
{
    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $abilities,
        public ApiTokenClient $client,
        public string $teamId,
        public string $teamName,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
        public ?string $lastUsedIp,
        public string $createdAt,
    ) {}

    public static function fromToken(ApiToken $token): self
    {
        $token->loadMissing('team:id,public_id,name');

        return new self(
            id: $token->public_id,
            name: $token->name,
            abilities: array_values(array_filter(array_map(ApiAbility::tryFrom(...), $token->abilities))),
            client: $token->client,
            teamId: $token->team->public_id,
            teamName: $token->team->name,
            expiresAt: $token->expires_at?->toIso8601String(),
            lastUsedAt: $token->last_used_at?->toIso8601String(),
            lastUsedIp: $token->last_used_ip,
            createdAt: $token->created_at->toIso8601String(),
        );
    }
}
