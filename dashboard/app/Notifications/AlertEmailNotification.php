<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Alerts\AlertDeliveryEvent;
use App\Services\Alerts\AlertMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An alert by email (docs/15 §3.5): calm, plain, one action. Sent with
 * notifyNow() from the delivery job, never queued on its own.
 */
final class AlertEmailNotification extends Notification
{
    public function __construct(
        public readonly AlertMessage $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->message->headline())
            ->greeting($this->message->headline())
            ->line($this->message->summary);

        foreach ($this->message->facts as $label => $value) {
            $mail->line($label.': '.$value);
        }

        if ($this->message->context !== []) {
            $mail->line(implode(' / ', $this->message->context));
        }

        $mail->action($this->message->event === AlertDeliveryEvent::Test ? 'Open notification settings' : 'Open in launchroom', $this->message->url);

        return $mail->salutation(' ');
    }
}
