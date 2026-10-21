<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\VerificationStatus;
use App\Models\Backups\BackupVerification;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One restore verification (docs/14 §10.4): what was restored, the checks
 * and what they found. Secret-free.
 */
final class BackupVerificationData extends Data
{
    /**
     * @param  list<BackupVerificationCheckData>  $checks
     */
    public function __construct(
        public string $id,
        public VerificationStatus $status,
        public BackupSubject $subject,
        public string $shortSnapshotId,
        public ?CarbonInterface $snapshotTakenAt,
        public array $checks,
        public ?int $tables,
        public ?int $rows,
        public ?int $files,
        public ?int $bytes,
        public ?CarbonInterface $restoredTo,
        public ?int $durationMs,
        public ?string $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $finishedAt,
    ) {}

    public static function fromModel(BackupVerification $verification): self
    {
        return new self(
            $verification->public_id,
            $verification->status,
            $verification->subject,
            substr($verification->snapshot_id, 0, 8),
            $verification->snapshot_taken_at,
            array_map(static fn (array $check): BackupVerificationCheckData => new BackupVerificationCheckData(
                (string) $check['name'], (bool) $check['passed'], isset($check['detail']) ? (string) $check['detail'] : null,
            ), $verification->checks),
            $verification->tables,
            $verification->rows,
            $verification->files,
            $verification->bytes,
            $verification->restored_to,
            $verification->duration_ms,
            $verification->error,
            $verification->created_at,
            $verification->finished_at,
        );
    }
}
