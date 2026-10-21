<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Enums\Alerts\AlertSeverity;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Change a channel: name, pause/resume, minimum severity, addresses, a new
 * URL (blank keeps the stored one), or a new webhook signing secret.
 */
final class AlertChannelUpdateInput extends Data
{
    /**
     * @param  list<string>|null  $recipients
     */
    public function __construct(
        #[Min(1), Max(80)]
        public ?string $name = null,
        public ?bool $enabled = null,
        public ?AlertSeverity $minSeverity = null,
        #[ArrayType, Max(10)]
        public ?array $recipients = null,
        #[Max(2048), SensitiveParameter]
        public ?string $secretUrl = null,
        public bool $rotateSecret = false,
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
        return ['name' => $this->name, 'enabled' => $this->enabled, 'secretUrl' => '[redacted]'];
    }
}
