<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupBlocker;
use Spatie\LaravelData\Data;

/**
 * An action on the Backups page: where to send it, or why it can't run.
 */
final class BackupActionData extends Data
{
    public function __construct(
        /** null: the action can't run now ($disabled says why). */
        public ?string $url,
        public ?BackupDisabledReasonData $disabled = null,
    ) {}

    public static function to(string $url): self
    {
        return new self($url);
    }

    public static function blocked(BackupBlocker $code, string $message, ?string $fixLabel = null, ?string $fixHref = null): self
    {
        return new self(null, new BackupDisabledReasonData($code, $message, $fixLabel, $fixHref));
    }
}
