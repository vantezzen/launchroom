<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Services\Instance\DashboardOrigin;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * A one-time link to choose a new password, printed on the server
 * (`./launchroom reset-password EMAIL` → `launchroom:users:sign-in-link`,
 * OD20): the way back in when no mail is set up, without a reset link ever
 * reaching a log. It is the regular password-reset token (single use,
 * expiring after `auth.passwords.users.expire` minutes, an earlier one
 * replaced), on the canonical dashboard origin. "Remember me" logins are
 * invalidated now; two-factor still applies at sign-in
 * (`./launchroom reset-owner-2fa` for an instance owner). Audited.
 */
final readonly class IssueSignInLink
{
    public function __construct(
        private DashboardOrigin $origin,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(string $email, AuditActor $actor): string
    {
        return DB::transaction(function () use ($email, $actor): string {
            $user = User::query()
                ->where('email', Str::lower(trim($email)))
                ->lockForUpdate()
                ->first();

            if (! $user instanceof User) {
                throw DomainRuleViolation::on('email', 'No account has this e-mail address.');
            }

            $broker = Password::broker();

            if (! $broker instanceof PasswordBroker) {
                throw new \LogicException('The password broker cannot create reset tokens.');
            }

            $token = $broker->createToken($user);
            $user->forceFill(['remember_token' => Str::random(60)])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::SignInLinkIssued,
                actor: $actor,
                subjectType: 'user',
                subjectId: (string) $user->id,
            ));

            return rtrim($this->origin->canonical(), '/').'/reset-password/'.rawurlencode($token).'?email='.rawurlencode($user->email);
        });
    }
}
