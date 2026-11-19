<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupBlocker;
use Spatie\LaravelData\Data;

/**
 * Why a backup action can't run now, with its fix: shown next to the
 * control, never only as a tooltip.
 */
final class BackupDisabledReasonData extends Data
{
    public function __construct(
        public BackupBlocker $code,
        /** One line of final copy. */
        public string $message,
        public ?string $fixLabel = null,
        /** A URL, or an anchor on the Backups page (`#setup`, `#setup-launchroom`, `#recovery-kit`). */
        public ?string $fixHref = null,
    ) {}
}
