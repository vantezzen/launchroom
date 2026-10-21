<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use App\Queries\Api\ApiTokenOptions;
use App\Support\Api\NewApiToken;
use Illuminate\Support\Facades\DB;

/**
 * Issues a personal access token bound to one of the user's teams (docs/16
 * §1): mandatory expiry from the allowed lifetimes, `read` always included,
 * no ability the user's role couldn't use, only the hash stored, audited.
 * Returns the plaintext once.
 */
final readonly class CreateApiToken
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<ApiAbility>  $abilities
     */
    public function handle(User $user, Team $team, string $name, array $abilities, int $expiresInDays, ApiTokenClient $client, AuditActor $actor): NewApiToken
    {
        $role = $user->roleIn($team) ?? throw DomainRuleViolation::forbidden("You aren't a member of that team.");

        $lifetimes = ApiTokenOptions::lifetimes();

        if (! in_array($expiresInDays, $lifetimes, true) || $expiresInDays > config()->integer('api.max_token_days')) {
            throw DomainRuleViolation::on('expiresInDays', 'Pick one of the offered lifetimes.');
        }

        $abilities = array_values(array_unique([ApiAbility::Read, ...$abilities], SORT_REGULAR));

        foreach ($abilities as $ability) {
            $permission = $ability->grants() ?? Permission::View;

            if (! $role->can($permission)) {
                throw DomainRuleViolation::on('abilities', "Your role in {$team->name} can't use \"{$ability->label()}\", so a token can't either.");
            }
        }

        $name = trim($name) === '' ? 'API token' : mb_substr(trim($name), 0, 80);

        return DB::transaction(function () use ($user, $team, $name, $abilities, $expiresInDays, $client, $actor): NewApiToken {
            $secret = $user->generateTokenString();

            $token = new ApiToken;
            $token->forceFill([
                'name' => $name,
                'token' => hash('sha256', $secret),
                'abilities' => array_map(static fn (ApiAbility $ability): string => $ability->value, $abilities),
                'expires_at' => now()->addDays($expiresInDays),
                'team_id' => $team->id,
                'client' => $client,
                'created_ip' => $actor->ip,
                'tokenable_type' => $user->getMorphClass(),
                'tokenable_id' => $user->id,
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ApiTokenCreated,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'api_token',
                subjectId: $token->public_id,
                meta: [
                    'name' => $name,
                    'abilities' => $token->abilities,
                    'expires_in_days' => $expiresInDays,
                    'client' => $client->value,
                ],
            ));

            return new NewApiToken($token, $token->id.'|'.$secret);
        });
    }
}
