<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Models\Transfers\DataTransfer;
use Spatie\LaravelData\Data;

/**
 * The download transfer an export writes into (protocol TransferTarget).
 */
final class TransferTarget extends Data
{
    public function __construct(
        public string $id,
        public int $chunkBytes,
    ) {}

    public static function of(DataTransfer $transfer): self
    {
        return new self($transfer->public_id, $transfer->chunk_bytes);
    }
}
