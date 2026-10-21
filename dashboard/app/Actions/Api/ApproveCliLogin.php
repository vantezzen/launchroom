<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Api\ApproveCliLoginInput;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\ApiTokenClient;
use App\Enums\AuditAction;
use App\Enums\CliLoginStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\CliLoginRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * A member approves a pending CLI sign-in (docs/16 §4): a token is created
 * for them in one of their teams (client `cli`) and its plaintext is kept
 * encrypted on the request until the CLI picks it up once.
 */
final readonly class ApproveCliLogin
{
    public function __construct(
        private CreateApiToken $createToken,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(CliLoginRequest $request, User $user, ApproveCliLoginInput $input, AuditActor $actor): CliLoginRequest
    {
        $team = Team::query()
            ->where('public_id', $input->team)
            ->whereIn('id', $user->teamIdsQuery())
            ->first() ?? throw DomainRuleViolation::on('team', "You aren't a member of that team.");

        return DB::transaction(function () use ($request, $user, $input, $team, $actor): CliLoginRequest {
            $request = CliLoginRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== CliLoginStatus::Pending || $request->isExpired()) {
                throw DomainRuleViolation::conflict('This sign-in request expired or was already answered. Run launchroom login again.');
            }

            $name = 'CLI'.($request->client_hostname !== null ? ' on '.$request->client_hostname : '');
            $new = $this->createToken->handle($user, $team, $name, $input->abilityList(), $input->expiresInDays, ApiTokenClient::Cli, $actor);

            $request->transitionTo(CliLoginStatus::Approved, [
                'user_id' => $user->id,
                'team_id' => $team->id,
                'personal_access_token_id' => $new->token->id,
                'token_ciphertext' => Crypt::encryptString($new->plainText),
                'decided_at' => now(),
            ]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::CliLoginApproved,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'api_token',
                subjectId: $new->token->public_id,
                meta: ['hostname' => $request->client_hostname, 'client' => $request->client_name, 'from_ip' => $request->ip],
            ));

            return $request;
        });
    }
}
