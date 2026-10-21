<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Services\Backups\RecoveryKey;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Sets or rotates the recovery passphrase (docs/14 §4). Only the Argon2id
 * parameters and the derived public key are stored; the next control-plane
 * snapshot seals the key bundle to the new key. Rotating requires a new
 * recovery kit confirmation.
 */
final readonly class SetRecoveryPassphrase
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(#[SensitiveParameter] string $passphrase, AuditActor $actor): void
    {
        if (mb_strlen($passphrase) < RecoveryKey::MIN_PASSPHRASE_LENGTH) {
            throw DomainRuleViolation::on('passphrase', 'Use at least '.RecoveryKey::MIN_PASSPHRASE_LENGTH.' characters. A few random words work well.');
        }

        if (mb_strlen($passphrase) > 1024) {
            throw DomainRuleViolation::on('passphrase', 'Use at most 1024 characters.');
        }

        $kdf = RecoveryKey::newKdf(
            config()->integer('launchroom.backups.recovery_kdf.opslimit'),
            config()->integer('launchroom.backups.recovery_kdf.memlimit'),
        );
        $publicKey = RecoveryKey::publicKey($passphrase, $kdf);
        // Authenticates key bundles (format 2, review L5).
        $macKey = base64_encode(RecoveryKey::macKey($passphrase, $kdf));

        DB::transaction(function () use ($kdf, $publicKey, $macKey, $actor): void {
            $settings = InstanceSettings::lockedForUpdate();
            $rotated = $settings->recovery_public_key !== null;

            $settings->forceFill([
                'recovery_kdf' => $kdf,
                'recovery_public_key' => $publicKey,
                'recovery_mac_key' => $macKey,
                'recovery_passphrase_set_at' => now(),
                'recovery_kit_confirmed_at' => null,
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RecoveryPassphraseSet,
                actor: $actor,
                subjectType: 'instance',
                meta: ['rotated' => $rotated],
            ));
        });
    }
}
