<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\ApiAbility;
use Spatie\LaravelData\Data;

/**
 * The token of an approved CLI sign-in, handed out once.
 */
final class CliTokenData extends Data
{
    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function __construct(
        public string $token,
        public string $expiresAt,
        public ApiTeamData $team,
        public array $abilities,
        public ApiUserData $user,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'expiresAt' => $this->expiresAt];
    }
}
