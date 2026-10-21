<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferPurpose;
use App\Enums\Transfers\TransferStatus;
use App\Models\Transfers\DataTransfer;
use Spatie\LaravelData\Data;

/**
 * A transfer as the UI, the API and the CLI see it (docs/14 §12.7). Never
 * carries keys, digests of chunks or link tokens.
 */
final class TransferData extends Data
{
    /**
     * @param  list<int>  $receivedChunks  indices received so far (while receiving)
     * @param  list<string>  $entries  first archive entries (volume imports)
     */
    public function __construct(
        public string $id,
        public TransferPurpose $purpose,
        public TransferDirection $direction,
        public TransferStatus $status,
        public string $fileName,
        public int $sizeBytes,
        public int $chunkBytes,
        public int $chunks,
        public array $receivedChunks,
        /** protocol DataFormat: postgres_custom, postgres_sql, mysql_sql, mongodb_archive, clickhouse_backup, redis_rdb, tar, zip */
        public ?string $format,
        public bool $compressed,
        public ?string $sourceVersion,
        public ?string $detail,
        public ?string $error,
        public ?string $createdAt,
        public ?string $expiresAt,
        public array $entries = [],
        /** The archive's single top folder, when it has one (volume imports). */
        public ?string $topFolder = null,
    ) {}

    public static function fromTransfer(DataTransfer $transfer): self
    {
        $sniff = $transfer->sniff ?? [];
        $entries = array_values(array_filter(is_array($sniff['entries'] ?? null) ? $sniff['entries'] : [], is_string(...)));

        return new self(
            $transfer->public_id,
            $transfer->purpose,
            $transfer->direction,
            $transfer->status,
            $transfer->file_name,
            $transfer->size_bytes,
            $transfer->chunk_bytes,
            $transfer->chunk_count,
            $transfer->status === TransferStatus::Receiving ? $transfer->receivedChunks() : [],
            $transfer->format,
            $transfer->compressed,
            $transfer->source_version,
            $transfer->detail,
            $transfer->error,
            $transfer->created_at?->toIso8601String(),
            $transfer->expires_at->toIso8601String(),
            array_slice($entries, 0, 20),
            is_string($sniff['topFolder'] ?? null) ? $sniff['topFolder'] : null,
        );
    }
}
