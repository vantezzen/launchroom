<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * A robot token in Settings → Robot tokens (docs/16 §1.4), never its secret.
 * `project`/`service` are its scope (null: every project).
 */
final class RobotTokenData extends Data
{
    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $hint,
        public array $abilities,
        /** Every ability the holder's role allows, including ones added later (D113); `abilities` is what that is now. */
        public bool $fullAccess,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $serviceId,
        public ?string $serviceName,
        public ?string $createdBy,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
        public ?string $lastUsedIp,
        public string $createdAt,
    ) {}

    public static function fromToken(ApiToken $token, User $robot): self
    {
        return new self(
            id: $token->public_id,
            name: $token->name,
            hint: $token->hint,
            abilities: $token->effectiveAbilities(),
            fullAccess: $token->full_access,
            projectId: $token->project?->public_id,
            projectName: $token->project?->name,
            serviceId: $token->service?->public_id,
            serviceName: $token->service?->name,
            createdBy: $robot->robotCreator?->name,
            expiresAt: $token->expires_at?->toIso8601String(),
            lastUsedAt: $token->last_used_at?->toIso8601String(),
            lastUsedIp: $token->last_used_ip,
            createdAt: $token->created_at->toIso8601String(),
        );
    }
}
