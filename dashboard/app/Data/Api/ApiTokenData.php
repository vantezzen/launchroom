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
        /** `lr_pat_…a1b2`, not secret; null for tokens older than the hint. */
        public ?string $hint,
        public array $abilities,
        /** Every ability the holder's role allows, including ones added later (D113); `abilities` is what that is now. */
        public bool $fullAccess,
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
            hint: $token->hint,
            abilities: $token->effectiveAbilities(),
            fullAccess: $token->full_access,
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
