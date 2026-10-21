<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Data\Transfers\Inputs\CreateTransferInput;
use App\Enums\Transfers\TransferPurpose;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\TransferStore;
use App\Support\PublicId;
use App\Support\Transfers\Bytes;

/**
 * Announces an upload to import (docs/14 §12.1): checks it fits the service
 * and the control-plane disk, then the chunks can come.
 */
final readonly class CreateUpload
{
    /** Unfinished uploads per service at a time. */
    private const int MAX_RECEIVING = 3;

    public function __construct(
        private TransferStore $store,
    ) {}

    public function handle(ServiceInstance $instance, CreateTransferInput $input, User $user): DataTransfer
    {
        $purpose = TransferPurpose::from($input->purpose);
        $target = ImportTarget::for($instance);

        if ($target->subject !== $purpose->subject()) {
            throw DomainRuleViolation::on('purpose', $purpose === TransferPurpose::DatabaseImport ? 'Only databases take a dump.' : 'Only services with volumes take an archive.');
        }

        if (! $target->importSupported()) {
            throw DomainRuleViolation::on('purpose', "Importing into {$target->database?->engine->label()} isn’t supported.");
        }

        $volume = $purpose === TransferPurpose::VolumeImport ? $target->volume($input->volume) : null;
        $max = config()->integer('launchroom.transfers.max_bytes');

        if ($input->sizeBytes > $max) {
            throw DomainRuleViolation::on('sizeBytes', 'This file is larger than '.Bytes::human($max).', the most an import takes.');
        }

        $free = $this->store->freeBytes();
        $needed = $input->sizeBytes + config()->integer('launchroom.transfers.free_space_margin_bytes');

        if ($free !== null && $free < $needed) {
            throw DomainRuleViolation::on('sizeBytes', 'The control-plane server doesn’t have room for this file: it needs '.Bytes::human($needed).', '.Bytes::human($free).' is free.');
        }

        $receiving = DataTransfer::query()
            ->where('service_instance_id', $instance->id)
            ->where('status', TransferStatus::Receiving->value)
            ->count();

        if ($receiving >= self::MAX_RECEIVING) {
            throw DomainRuleViolation::conflict('Finish or cancel the other uploads to this service first.');
        }

        $chunk = config()->integer('launchroom.transfers.chunk_bytes');
        $transfer = new DataTransfer([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'volume_id' => $volume?->id,
            'direction' => $purpose->direction(),
            'purpose' => $purpose,
            'file_name' => $this->fileName($input->fileName),
            'size_bytes' => $input->sizeBytes,
            'chunk_bytes' => $chunk,
            'chunk_count' => intdiv($input->sizeBytes + $chunk - 1, $chunk),
            'created_by' => $user->id,
            'expires_at' => now()->addHours(config()->integer('launchroom.transfers.upload_ttl_hours')),
        ]);
        $transfer->public_id = PublicId::generate(DataTransfer::publicIdPrefix());
        $this->store->initialise($transfer);
        $transfer->save();

        return $transfer;
    }

    /**
     * Display only: the last path component, printable characters, ≤ 255 bytes.
     */
    private function fileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = mb_strcut(trim($name), 0, 255);

        return in_array($name, ['', '.', '..'], true) ? 'upload' : $name;
    }
}
