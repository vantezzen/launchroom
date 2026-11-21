<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Team;
use App\Models\User;
use App\Services\Api\ApiTokenIssuer;
use App\Support\Api\NewApiToken;
use Illuminate\Support\Facades\DB;

/**
 * Issues a personal access token bound to one of the user's teams (docs/16
 * §1): mandatory expiry from the allowed lifetimes, `read` always included,
 * full access (D113) or a list, no ability the user's role couldn't use, only the hash stored, audited.
 * Returns the plaintext once.
 */
final readonly class CreateApiToken
{
    public function __construct(
        private RecordAuditEvent $audit,
        private ApiTokenIssuer $issuer,
    ) {}

    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function handle(User $user, Team $team, string $name, array $abilities, int $expiresInDays, ApiTokenClient $client, AuditActor $actor, bool $fullAccess = false): NewApiToken
    {
        $role = $user->roleIn($team) ?? throw DomainRuleViolation::forbidden("You aren't a member of that team.");

        return DB::transaction(function () use ($user, $team, $role, $name, $abilities, $expiresInDays, $client, $actor, $fullAccess): NewApiToken {
            $new = $this->issuer->issue($user, $team, $role, $name, $abilities, $expiresInDays, $client, $actor->ip, fullAccess: $fullAccess);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ApiTokenCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'api_token',
                subjectId: $new->token->public_id,
                meta: [
                    'name' => $new->token->name,
                    'abilities' => $new->token->abilities,
                    'full_access' => $fullAccess,
                    'expires_in_days' => $expiresInDays,
                    'client' => $client->value,
                ],
            ));

            return $new;
        });
    }
}
