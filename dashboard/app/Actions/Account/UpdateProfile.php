<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

/**
 * Name and email of the signed-in account. A new email must be verified
 * again, and the old address hears about the change.
 */
final readonly class UpdateProfile
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, string $name, string $email, AuditActor $actor): void
    {
        $previousEmail = $user->email;

        $emailChanged = DB::transaction(function () use ($user, $name, $email, $actor): bool {
            $user->fill(['name' => $name, 'email' => $email]);
            $emailChanged = $user->isDirty('email');

            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            $user->save();

            if ($emailChanged) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::AccountEmailChanged,
                    actor: $actor,
                    subjectType: 'user',
                    subjectId: (string) $user->id,
                ));
            }

            return $emailChanged;
        });

        if (! $emailChanged) {
            return;
        }

        Notification::route('mail', $previousEmail)->notify(new EmailChangedNotification($user->name));

        if (Features::enabled(Features::emailVerification())) {
            $user->sendEmailVerificationNotification();
        }
    }
}
