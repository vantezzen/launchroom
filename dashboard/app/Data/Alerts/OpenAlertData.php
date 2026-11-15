<?php

declare(strict_types=1);

namespace App\Data\Alerts;

use App\Enums\Alerts\AlertKind;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Backups\BackupAlertKind;
use Spatie\LaravelData\Data;

/**
 * A firing alert where the broken thing is shown (docs/15 §3.6): canvas
 * nodes, the service panel, server cards and project cards. `label` is the
 * chip's word or two ("Unhealthy", "Errors 8%", "Disk 93%"); `title` the
 * full sentence for its tooltip; `href` the alert's filtered view
 * (App\Support\Links). `kind`/`backupKind` say which cause of the rule it
 * is; `value` is the measured percentage, when there is one.
 */
final class OpenAlertData extends Data
{
    public function __construct(
        public string $id,
        public AlertRule $rule,
        public AlertSeverity $severity,
        public string $label,
        public string $title,
        public ?string $href,
        public string $openedAt,
        public ?AlertKind $kind = null,
        public ?BackupAlertKind $backupKind = null,
        public ?float $value = null,
    ) {}
}
