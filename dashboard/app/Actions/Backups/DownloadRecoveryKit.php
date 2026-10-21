<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\RecoveryKitDocument;
use Illuminate\Support\Facades\DB;

/**
 * Produces the recovery kit (docs/14 §4) for the instance owner: destination,
 * credentials, repository password and instructions; never the passphrase.
 * Every download is audited.
 */
final readonly class DownloadRecoveryKit
{
    public function __construct(
        private DestinationSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AuditActor $actor): string
    {
        $destination = BackupDestination::forInstance();
        $settings = InstanceSettings::current();

        if (! $destination instanceof BackupDestination) {
            throw DomainRuleViolation::on('destination', 'Add the control-plane backup destination first.');
        }

        if ($settings->recovery_public_key === null) {
            throw DomainRuleViolation::on('passphrase', 'Set a recovery passphrase first.');
        }

        $document = RecoveryKitDocument::render($destination, $this->secrets->reveal($destination), config()->string('app.url'));

        DB::transaction(function () use ($actor, $destination): void {
            InstanceSettings::lockedForUpdate()->forceFill(['recovery_kit_downloaded_at' => now()])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RecoveryKitDownloaded,
                actor: $actor,
                subjectType: 'backup_destination',
                subjectId: $destination->public_id,
            ));
        });

        return $document;
    }
}
