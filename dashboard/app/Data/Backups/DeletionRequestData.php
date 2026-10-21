<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\DeletionRequestStatus;
use App\Models\Backups\BackupRun;
use App\Models\Backups\DeletionRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Data;

/**
 * A deletion waiting for its final backup (docs/14 §9.13), for the danger
 * zones: what runs, why it is blocked, and the retry / "delete without final
 * backup" actions (secret-free; run errors are masked at the source).
 */
final class DeletionRequestData extends Data
{
    /**
     * @param  list<array{service: string, volume: string|null, status: string, error: string|null}>  $runs
     * @param  array{retry: string, skip: string}  $urls
     */
    public function __construct(
        public string $id,
        public string $subjectType,
        public string $subjectName,
        public DeletionRequestStatus $status,
        public ?string $error,
        public array $runs,
        public ?CarbonInterface $createdAt,
        public bool $canRetry,
        public bool $canSkip,
        public array $urls,
    ) {}

    public static function fromRequest(DeletionRequest $request, User $user): self
    {
        return new self(
            $request->public_id,
            $request->subject_type,
            $request->subject_name,
            $request->status,
            $request->error,
            array_values($request->backupRuns()->map(static fn (BackupRun $run): array => [
                'service' => $run->serviceInstance?->service->name ?? 'Service',
                'volume' => $run->volume?->mount_path,
                'status' => $run->status->value,
                'error' => $run->error,
            ])->all()),
            $request->created_at,
            $request->status === DeletionRequestStatus::Blocked && Gate::forUser($user)->allows('retry', $request),
            ! $request->status->allowsDeletion() && Gate::forUser($user)->allows('skipFinalBackup', $request),
            [
                'retry' => route('deletions.retry', ['deletion' => $request->public_id]),
                'skip' => route('deletions.skip', ['deletion' => $request->public_id]),
            ],
        );
    }

    /**
     * The open request of a subject, for a page prop.
     */
    public static function openFor(string $type, int $id, User $user): ?self
    {
        $request = DeletionRequest::openFor($type, $id);

        return $request instanceof DeletionRequest ? self::fromRequest($request, $user) : null;
    }
}
