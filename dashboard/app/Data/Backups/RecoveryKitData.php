<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\RecoveryKitStep;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * The recovery kit flow for instance owners (docs/14 §4): passphrase, then
 * download, then "Keep it safe", then done.
 */
final class RecoveryKitData extends Data
{
    public function __construct(
        public RecoveryKitStep $step,
        public bool $destinationConfigured,
        public bool $passphraseSet,
        public ?CarbonInterface $passphraseSetAt,
        public ?CarbonInterface $kitDownloadedAt,
        public ?CarbonInterface $kitConfirmedAt,
        public bool $needsAttention,
        /** POST {passphrase, passphrase_confirmation}. */
        public string $passphraseUrl,
        /** GET, a file download; records the download (header X-Kit-Downloaded-At). */
        public string $downloadUrl,
        /** POST "I've put both away". */
        public BackupActionData $confirm,
        /** Why a kit saved before must be saved again; null on first setup or once confirmed. */
        #[LiteralTypeScriptType("'passphrase_changed' | 'storage_changed' | null")]
        public ?string $resetReason = null,
    ) {}
}
