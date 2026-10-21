<?php

declare(strict_types=1);

namespace App\Actions\Api;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\CliLoginStatus;
use App\Models\CliLoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A member refuses a CLI sign-in: the CLI's next poll is `access_denied`.
 */
final readonly class DenyCliLogin
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(CliLoginRequest $request, User $user, AuditActor $actor): void
    {
        DB::transaction(function () use ($request, $user, $actor): void {
            $request = CliLoginRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->status !== CliLoginStatus::Pending) {
                return;
            }

            $request->transitionTo(CliLoginStatus::Denied, ['user_id' => $user->id, 'decided_at' => now()]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::CliLoginDenied,
                actor: $actor,
                teamId: $user->current_team_id,
                subjectType: 'cli_login',
                subjectId: $request->public_id,
                meta: ['hostname' => $request->client_hostname, 'from_ip' => $request->ip],
            ));
        });
    }
}
