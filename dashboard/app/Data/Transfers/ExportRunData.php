<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ExportSource;
use App\Models\Transfers\DataExportRun;
use Spatie\LaravelData\Data;

/**
 * An export as the UI, the API and the CLI see it (docs/14 §12.7).
 */
final class ExportRunData extends Data
{
    public function __construct(
        public string $id,
        public DataSubject $subject,
        public ExportSource $source,
        public ?string $snapshot,
        public ?TransferVolumeData $volume,
        public ExportRunStatus $status,
        public string $fileName,
        /** Bytes received so far while preparing, the file's size once ready. */
        public int $sizeBytes,
        public ?string $format,
        public bool $compressed,
        public ?string $error,
        public ?string $createdAt,
        public ?string $readyAt,
        public ?string $expiresAt,
    ) {}

    public static function fromRun(DataExportRun $run): self
    {
        $run->loadMissing(['volume', 'transfer']);
        $transfer = $run->transfer;

        return new self(
            $run->public_id,
            $run->subject,
            $run->source,
            $run->snapshot_id === null ? null : substr($run->snapshot_id, 0, 8),
            TransferVolumeData::fromVolume($run->volume),
            $run->status,
            $run->file_name,
            (int) $transfer?->size_bytes,
            $transfer?->format,
            $transfer !== null && $transfer->compressed,
            $run->error,
            $run->created_at?->toIso8601String(),
            $run->ready_at?->toIso8601String(),
            $run->status === ExportRunStatus::Ready ? $transfer?->expires_at->toIso8601String() : null,
        );
    }
}
