<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Enums\Alerts\AlertChannelKind;
use App\Enums\Alerts\AlertSeverity;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Add a notification channel (docs/15 §3.5). `secretUrl` is the Slack,
 * Discord or webhook address (named so it is never flashed as old input);
 * `recipients` the email addresses. Kind-specific rules are checked by
 * CreateAlertChannel.
 */
final class AlertChannelInput extends Data
{
    /**
     * @param  list<string>|null  $recipients
     */
    public function __construct(
        public AlertChannelKind $kind,
        #[Max(80)]
        public ?string $name = null,
        #[ArrayType, Max(10)]
        public ?array $recipients = null,
        #[Max(2048), SensitiveParameter]
        public ?string $secretUrl = null,
        public AlertSeverity $minSeverity = AlertSeverity::Warning,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return ['recipients.*' => ['string', 'email:rfc', 'max:254']];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'name' => $this->name, 'secretUrl' => '[redacted]'];
    }
}
