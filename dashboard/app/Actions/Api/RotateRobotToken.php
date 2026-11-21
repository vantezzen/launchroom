<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\ApiAbility;
use App\Enums\ApiTokenClient;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\ApiToken;
use App\Models\User;
use App\Queries\Api\ApiTokenOptions;
use App\Services\Api\ApiTokenIssuer;
use App\Support\Api\NewApiToken;
use Illuminate\Support\Facades\DB;

/**
 * A new secret for a robot (docs/16 §1.4): same robot, name, abilities
 * (or full access), scope and lifetime; the old token stops at once. Returns the plaintext
 * once.
 */
final readonly class RotateRobotToken
{
    public function __construct(
        private RecordAuditEvent $audit,
        private ApiTokenIssuer $issuer,
    ) {}

    public function handle(ApiToken $token, AuditActor $actor): NewApiToken
    {
        return DB::transaction(function () use ($token, $actor): NewApiToken {
            $old = ApiToken::query()->whereKey($token->id)->lockForUpdate()->first()
                ?? throw DomainRuleViolation::conflict('This token was already rotated or revoked.');
            $robot = User::query()->whereKey($old->tokenable_id)->whereNotNull('robot_team_id')->firstOrFail();
            $old->loadMissing(['team', 'project', 'service']);

            $abilities = array_values(array_filter(array_map(ApiAbility::tryFrom(...), $old->abilities)));
            $new = $this->issuer->issue($robot, $old->team, TeamRole::Admin, $old->name, $abilities, $this->lifetime($old), ApiTokenClient::Robot, $actor->ip, $old->project, $old->service, $old->full_access);
            $old->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RobotTokenRotated,
                actor: $actor,
                teamId: $old->team_id,
                subjectType: 'api_token',
                subjectId: $new->token->public_id,
                meta: ['name' => $old->name, 'replaces' => $old->public_id],
            ));

            return $new;
        });
    }

    /** The offered lifetime closest to the old token's (it was one of them). */
    private function lifetime(ApiToken $token): int
    {
        $days = $token->expires_at === null ? ApiTokenOptions::defaultLifetime() : (int) round($token->created_at->diffInDays($token->expires_at, true));
        $lifetimes = ApiTokenOptions::lifetimes();
        usort($lifetimes, static fn (int $a, int $b): int => abs($a - $days) <=> abs($b - $days));

        return $lifetimes[0];
    }
}
