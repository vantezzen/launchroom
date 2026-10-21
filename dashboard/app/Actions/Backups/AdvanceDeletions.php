<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\Backups\DeletionRequestStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\DeletionRequest;
use App\Services\Backups\FinalBackups;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves deletions that wait for final backups forward (docs/14 §9.13),
 * called by the backup tick: backing_up → ready/blocked from their runs,
 * then ready/overridden environments and projects are deleted. A deletion
 * that can't run yet (another backup started meanwhile) is retried next tick.
 */
final readonly class AdvanceDeletions
{
    public function __construct(
        private FinalBackups $finalBackups,
        private ContinueDeletion $continue,
    ) {}

    public function handle(): int
    {
        $advanced = 0;

        /** @var list<int> $ids */
        $ids = DeletionRequest::query()
            ->whereIn('status', [DeletionRequestStatus::BackingUp->value, DeletionRequestStatus::Ready->value, DeletionRequestStatus::Overridden->value])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($ids as $id) {
            try {
                $request = DB::transaction(function () use ($id): ?DeletionRequest {
                    $request = DeletionRequest::query()->whereKey($id)->lockForUpdate()->first();

                    if ($request instanceof DeletionRequest) {
                        $this->finalBackups->refresh($request);
                    }

                    return $request;
                });

                if ($request instanceof DeletionRequest && $request->status->allowsDeletion() && $request->subject_type !== DeletionRequest::SERVICE_INSTANCE) {
                    $advanced += $this->continue->handle($request, AuditActor::system()) instanceof DeletionOutcome ? 1 : 0;
                }
            } catch (DomainRuleViolation) {
                // Not now (e.g. another backup runs); the next tick tries again.
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $advanced;
    }
}
