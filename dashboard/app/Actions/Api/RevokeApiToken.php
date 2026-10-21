<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;

/**
 * Revokes (deletes) a token: the next request with it is 401. Audited.
 */
final readonly class RevokeApiToken
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ApiToken $token, AuditActor $actor): void
    {
        DB::transaction(function () use ($token, $actor): void {
            $token->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ApiTokenRevoked,
                actor: $actor,
                teamId: $token->team_id,
                subjectType: 'api_token',
                subjectId: $token->public_id,
                meta: ['name' => $token->name],
            ));
        });
    }
}
