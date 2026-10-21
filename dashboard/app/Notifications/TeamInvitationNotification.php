<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The invitation e-mail. The token travels only here; the queued payload is
 * encrypted (docs/10 §2.1 jobs).
 */
final class TeamInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $teamName,
        public readonly string $inviterName,
        #[SensitiveParameter]
        private readonly string $token,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You’ve been invited to {$this->teamName} on launchroom")
            ->line("{$this->inviterName} invited you to join the team {$this->teamName}.")
            ->action('Accept invitation', route('invitations.show', ['token' => $this->token]))
            ->line('The link works once and expires in '.config()->integer('launchroom.invitations.ttl_days').' days.');
    }
}
