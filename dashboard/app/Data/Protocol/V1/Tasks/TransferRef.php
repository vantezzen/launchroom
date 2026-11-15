<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Models\Transfers\DataTransfer;
use Spatie\LaravelData\Data;

/**
 * An uploaded file a task reads (protocol TransferRef, D55).
 */
final class TransferRef extends Data
{
    public function __construct(
        public string $id,
        public int $sizeBytes,
        public int $chunkBytes,
        public int $chunks,
        public string $sha256,
    ) {}

    public static function of(DataTransfer $transfer): self
    {
        return new self($transfer->public_id, $transfer->size_bytes, $transfer->chunk_bytes, $transfer->chunk_count, (string) $transfer->digest);
    }
}
