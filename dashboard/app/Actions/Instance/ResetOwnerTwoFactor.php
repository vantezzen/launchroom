<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin escape hatch for a locked-out instance owner (docs/11 §2: run on the
 * server through `./launchroom reset-owner-2fa EMAIL`, i.e. with host access).
 * Removes the owner's TOTP secret and recovery codes; the password stays and
 * they can set two-factor up again (recommended, not required: docs/09 R10).
 * "Remember me" logins are invalidated. Audited.
 */
final readonly class ResetOwnerTwoFactor
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(string $email, AuditActor $actor): User
    {
        return DB::transaction(function () use ($email, $actor): User {
            $owner = User::query()
                ->where('email', Str::lower(trim($email)))
                ->where('is_instance_owner', true)
                ->lockForUpdate()
                ->first();

            if (! $owner instanceof User) {
                throw DomainRuleViolation::on('email', 'No instance owner has this e-mail address.');
            }

            $owner->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => Str::random(60),
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::OwnerTwoFactorReset,
                actor: $actor,
                subjectType: 'user',
                subjectId: (string) $owner->id,
            ));

            return $owner;
        });
    }
}
