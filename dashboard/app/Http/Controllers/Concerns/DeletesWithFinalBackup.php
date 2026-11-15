<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Data\Inertia\ToastData;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\ToastType;
use App\Exceptions\DomainRuleViolation;
use App\Http\Toast;
use App\Models\Backups\DeletionRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Deleting a project or environment whose data gets a final backup first
 * (docs/14 §9.13).
 */
trait DeletesWithFinalBackup
{
    private function authorizeSkippingFinalBackup(bool $skip, int $teamId): void
    {
        if ($skip) {
            Gate::authorize('skipFinalBackup', [DeletionRequest::class, $teamId]);
        }
    }

    /**
     * @param  'project'|'environment'  $noun
     * @param  string  $blockedHint  where the page lets the user retry or skip the failed backup
     *
     * @throws DomainRuleViolation when another backup or restore runs
     */
    private function flashDeletionOutcome(DeletionOutcome $outcome, string $name, string $noun, string $blockedHint = ''): void
    {
        if ($outcome === DeletionOutcome::Busy) {
            throw DomainRuleViolation::on($noun, "A backup or restore of a service in this {$noun} is running. Wait for it to finish, then delete the {$noun}.");
        }

        Toast::show(match ($outcome) {
            DeletionOutcome::Deleted => new ToastData(ToastType::Success, "{$name} deleted. Its services are being stopped."),
            DeletionOutcome::Blocked => new ToastData(ToastType::Error, trim("{$name} was not deleted: its final backup failed. {$blockedHint}")),
            DeletionOutcome::WaitingForFinalBackup => new ToastData(ToastType::Info, "Taking a final backup of {$name}'s data first. The {$noun} is deleted once the backup succeeds."),
        });
    }
}
