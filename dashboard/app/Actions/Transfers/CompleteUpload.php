<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Data\Services\DatabaseSource;
use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferPurpose;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\FileSniffer;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\SniffResult;
use App\Services\Transfers\TransferStore;

/**
 * Finishes an upload (docs/14 §12.1): every chunk is there, the file digest
 * is fixed, and the file is recognised (format, versions; a dump newer than
 * the target is refused naming both versions). A file that can't be
 * imported is failed and deleted at once.
 */
final readonly class CompleteUpload
{
    public function __construct(
        private TransferStore $store,
        private FileSniffer $sniffer,
    ) {}

    public function handle(DataTransfer $transfer): DataTransfer
    {
        if ($transfer->direction !== TransferDirection::Upload) {
            throw DomainRuleViolation::conflict('Only uploads are completed this way.');
        }

        if ($transfer->status === TransferStatus::Ready) {
            return $transfer;
        }

        if ($transfer->status !== TransferStatus::Receiving) {
            throw DomainRuleViolation::conflict('This upload has ended. Upload the file again.');
        }

        $missing = $transfer->chunk_count - count($transfer->receivedChunks());

        if ($missing > 0) {
            throw DomainRuleViolation::on('chunks', "{$missing} of {$transfer->chunk_count} chunks are still missing.");
        }

        $digest = $transfer->digestOver($transfer->chunk_count);

        if ($digest === null || $transfer->lengthOver($transfer->chunk_count) !== $transfer->size_bytes) {
            throw DomainRuleViolation::on('chunks', 'The chunks don’t add up to the announced size. Upload the file again.');
        }

        $transfer->loadMissing('serviceInstance.service');
        $result = $this->sniff($transfer);
        $refusal = $this->refusal($transfer, $result);

        if ($refusal !== null) {
            $transfer->transitionTo(TransferStatus::Failed, $refusal);
            $this->store->delete($transfer);

            throw DomainRuleViolation::on('file', $refusal);
        }

        $transfer->forceFill([
            'digest' => $digest,
            'format' => $result->format,
            'compressed' => $result->compressed,
            'source_version' => $result->sourceVersionLabel(),
            'detail' => $result->detail(),
            'sniff' => array_filter([
                'family' => $result->family,
                'serverVersion' => $result->serverVersion,
                'toolVersion' => $result->toolVersion,
                'entries' => $result->entries === [] ? null : array_slice($result->entries, 0, 200),
                'entriesComplete' => $result->entriesComplete,
                'topFolder' => $result->topFolder(),
            ], static fn (mixed $value): bool => $value !== null),
            'expires_at' => now()->addHours(config()->integer('launchroom.transfers.upload_ttl_hours')),
        ]);
        $transfer->transitionTo(TransferStatus::Ready);

        return $transfer;
    }

    private function sniff(DataTransfer $transfer): SniffResult
    {
        $head = $this->store->get($transfer, 0);

        if ($transfer->purpose === TransferPurpose::VolumeImport) {
            $last = $transfer->chunk_count - 1;
            $tail = $last === 0 ? $head : $this->store->get($transfer, $last);

            return $this->sniffer->archive($head, $tail);
        }

        return $this->sniffer->database($head);
    }

    private function refusal(DataTransfer $transfer, SniffResult $result): ?string
    {
        if ($transfer->purpose === TransferPurpose::VolumeImport) {
            return $result->format === null ? 'launchroom couldn’t recognise this file. Upload a .tar, .tar.gz or .zip archive.' : null;
        }

        $target = ImportTarget::for($transfer->serviceInstance);
        $database = $target->database;

        if (! $database instanceof DatabaseSource) {
            return 'This service is not a database.';
        }

        return FileSniffer::refusal($result, $target->engine(), $database->version, $transfer->serviceInstance->service->name);
    }
}
