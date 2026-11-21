<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\ApiToken;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Queries\Api\ApiTokenOptions;
use App\Support\Api\NewApiToken;
use SensitiveParameter;

/**
 * Writes one token row for personal and robot tokens alike (docs/16 §1.1,
 * §1.4): an offered lifetime, `read` always included, nothing $role
 * couldn't use, only the hash stored. A full-access token (D113) records
 * every ability $role can use today; it gets later ones by its flag.
 * Returns the plaintext once. Callers authorize, run it in their
 * transaction and audit.
 */
final class ApiTokenIssuer
{
    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function issue(
        User $holder,
        Team $team,
        TeamRole $role,
        string $name,
        array $abilities,
        int $expiresInDays,
        ApiTokenClient $client,
        ?string $ip,
        ?Project $project = null,
        ?Service $service = null,
        bool $fullAccess = false,
    ): NewApiToken {
        if (! in_array($expiresInDays, ApiTokenOptions::lifetimes(), true) || $expiresInDays > config()->integer('api.max_token_days')) {
            throw DomainRuleViolation::on('expiresInDays', 'Pick one of the offered lifetimes.');
        }

        $abilities = $fullAccess
            ? array_values(array_filter(ApiAbility::cases(), static fn (ApiAbility $ability): bool => $role->can($ability->grants() ?? Permission::View)))
            : $this->withAlwaysGranted($abilities);

        foreach ($abilities as $ability) {
            if (! $role->can($ability->grants() ?? Permission::View)) {
                throw DomainRuleViolation::on('abilities', "Your role in {$team->name} can't use \"{$ability->label()}\", so a token can't either.");
            }
        }

        $secret = $holder->generateTokenString();

        $token = new ApiToken;
        $token->forceFill([
            'name' => trim($name) === '' ? 'API token' : mb_substr(trim($name), 0, 80),
            'token' => hash('sha256', $secret),
            'abilities' => array_map(static fn (ApiAbility $ability): string => $ability->value, $abilities),
            'full_access' => $fullAccess,
            'expires_at' => now()->addDays($expiresInDays),
            'team_id' => $team->id,
            'client' => $client,
            'created_ip' => $ip,
            'hint' => $this->hint($secret),
            'tokenable_type' => $holder->getMorphClass(),
            'tokenable_id' => $holder->id,
            'project_id' => $service instanceof Service ? $service->project_id : $project?->id,
            'service_id' => $service?->id,
        ])->save();

        return new NewApiToken($token, $token->id.'|'.$secret);
    }

    /**
     * @param  list<ApiAbility>  $abilities
     * @return list<ApiAbility>
     */
    private function withAlwaysGranted(array $abilities): array
    {
        return array_values(array_unique([...array_filter(ApiAbility::cases(), static fn (ApiAbility $ability): bool => $ability->alwaysGranted()), ...$abilities], SORT_REGULAR));
    }

    /**
     * `lr_pat_…a1b2`: the configured prefix and the secret's last four
     * characters (Sanctum's CRC32 checksum), nothing that helps guess it.
     */
    private function hint(#[SensitiveParameter] string $secret): string
    {
        return config()->string('sanctum.token_prefix').'…'.mb_substr($secret, -4);
    }
}
