<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Models\Backups\BackupDestination;
use App\Models\InstanceSettings;
use App\Models\User;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Recovery kit state (docs/14 §4). `needsAttention` drives the persistent
 * warning until the owner confirms a kit for the current passphrase and
 * control-plane destination.
 */
final class RecoveryKitStatusData extends Data
{
    public function __construct(
        public bool $destinationConfigured,
        public bool $passphraseSet,
        public ?CarbonInterface $passphraseSetAt,
        public ?CarbonInterface $kitDownloadedAt,
        public ?CarbonInterface $kitConfirmedAt,
        public bool $needsAttention,
    ) {}

    /**
     * The shared prop: only instance owners see (and act on) the kit.
     */
    public static function forUser(mixed $user): ?self
    {
        if (! $user instanceof User || ! $user->is_instance_owner) {
            return null;
        }

        return self::fromSettings(InstanceSettings::current(), BackupDestination::forInstance() instanceof BackupDestination);
    }

    public static function fromSettings(InstanceSettings $settings, bool $destinationConfigured): self
    {
        $passphraseSet = $settings->recovery_public_key !== null;

        return new self(
            $destinationConfigured,
            $passphraseSet,
            $settings->recovery_passphrase_set_at,
            $settings->recovery_kit_downloaded_at,
            $settings->recovery_kit_confirmed_at,
            ! $destinationConfigured || ! $passphraseSet || $settings->recovery_kit_confirmed_at === null,
        );
    }
}
