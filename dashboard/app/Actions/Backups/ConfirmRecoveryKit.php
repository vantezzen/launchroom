<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\DB;

/**
 * The owner confirms the recovery kit is stored safely; this ends the
 * persistent warning (docs/14 §4, `instance_settings.recovery_kit_confirmed_at`).
 */
final readonly class ConfirmRecoveryKit
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AuditActor $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $settings = InstanceSettings::lockedForUpdate();

            if ($settings->recovery_public_key === null || $settings->recovery_kit_downloaded_at === null) {
                throw DomainRuleViolation::on('recoveryKit', 'Set a recovery passphrase and download the recovery kit first.');
            }

            $settings->forceFill(['recovery_kit_confirmed_at' => now()])->save();

            $this->audit->handle(new AuditEntryData(action: AuditAction::RecoveryKitConfirmed, actor: $actor, subjectType: 'instance'));
        });
    }
}
