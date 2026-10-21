<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Data\Transfers\ChunkReceiptData;
use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\TransferStore;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Stores chunk $index of a transfer that is receiving (docs/14 §12.1): an
 * upload's chunk from the browser or the CLI (with its SHA-256, checked), or
 * an export's chunk from launchroomd. Idempotent: a chunk sent again
 * replaces the earlier copy.
 */
final readonly class StoreTransferChunk
{
    public function __construct(
        private TransferStore $store,
    ) {}

    public function handle(DataTransfer $transfer, int $index, #[SensitiveParameter] string $body, ?string $sha256 = null): ChunkReceiptData
    {
        if ($transfer->status !== TransferStatus::Receiving) {
            throw DomainRuleViolation::conflict('This transfer takes no more chunks.');
        }

        $length = strlen($body);

        if ($transfer->direction === TransferDirection::Upload) {
            if ($index < 0 || $index >= $transfer->chunk_count) {
                throw DomainRuleViolation::on('index', "This file has {$transfer->chunk_count} chunks, numbered from 0.");
            }

            if ($length !== $transfer->expectedChunkLength($index)) {
                throw DomainRuleViolation::on('chunk', "Chunk {$index} must be {$transfer->expectedChunkLength($index)} bytes, not {$length}.");
            }
        } else {
            $limit = intdiv(config()->integer('launchroom.transfers.max_bytes') + $transfer->chunk_bytes - 1, $transfer->chunk_bytes);

            if ($index < 0 || $index >= $limit || $length < 1 || $length > $transfer->chunk_bytes) {
                throw DomainRuleViolation::on('chunk', 'The chunk is out of range or too large.');
            }
        }

        $digest = hash('sha256', $body);

        if ($sha256 !== null && ! hash_equals($digest, strtolower($sha256))) {
            throw DomainRuleViolation::on('chunk', "Chunk {$index} arrived damaged. Send it again.");
        }

        $this->store->put($transfer, $index, $body);

        return DB::transaction(function () use ($transfer, $index, $digest, $length): ChunkReceiptData {
            $row = DataTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $chunks = $row->chunk_digests ?? [];
            $chunks[$index] = ['d' => $digest, 'n' => $length];
            $row->chunk_digests = $chunks;

            if ($row->direction === TransferDirection::Download) {
                // So far, for "Preparing download · 120 MB so far".
                $row->size_bytes = array_sum(array_map(static fn (array $chunk): int => $chunk['n'], $chunks));
            }

            $row->forceFill(['last_used_at' => now()])->save();
            $transfer->setRawAttributes($row->getAttributes(), true);

            return new ChunkReceiptData($index, count($chunks), $row->chunk_count);
        });
    }
}
