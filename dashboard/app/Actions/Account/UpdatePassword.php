<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Sets a new password for the signed-in account (validated against the
 * current one by the request).
 */
final readonly class UpdatePassword
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, #[SensitiveParameter] string $password, AuditActor $actor): void
    {
        DB::transaction(function () use ($user, $password, $actor): void {
            $user->update(['password' => $password]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AccountPasswordChanged,
                actor: $actor,
                subjectType: 'user',
                subjectId: (string) $user->id,
            ));
        });
    }
}
