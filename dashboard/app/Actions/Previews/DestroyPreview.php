<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Environments\DeleteEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\Previews\PreviewStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Previews\AdvancePreviewsJob;
use App\Jobs\Previews\ReportPreviewToGitHubJob;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewShareLink;
use Illuminate\Support\Facades\DB;

/**
 * Tears a preview down (docs/06 §5 "Cleanup"): PR closed or merged, expired
 * after inactivity, filtered out (e.g. its label removed), or found closed
 * by the orphan sweep.
 *
 * The environment goes through App\Actions\Environments\DeleteEnvironment
 * (no typed confirmation): workloads leave the NodeStates, the edges drop
 * the routes, the rows go (deployments, domains, volume rows, the variables
 * that only applied here). Docker volumes stay on the servers for
 * `launchroom.volumes.preview_retention_hours`, then are deleted through an
 * explicit NodeState request (VolumeRetirements, docs/05 §1.1). While a backup or
 * restore of one of its databases runs (e.g. a clone), or a final backup of
 * data with backups configured (docs/14 §9.13), the removal waits:
 * `data.pendingClose` makes the preview tick retry it. Share links are
 * revoked, the GitHub deployment goes inactive, and a queued preview may
 * take the slot.
 */
final readonly class DestroyPreview
{
    public function __construct(
        private DeleteEnvironment $deleteEnvironment,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Preview $preview, PreviewStatus $to, string $reason, ?AuditActor $actor = null): void
    {
        $actor ??= AuditActor::system();
        $preview = Preview::query()->whereKey($preview->id)->firstOrFail();
        $environment = $preview->environment_id === null ? null : Environment::query()->whereKey($preview->environment_id)->first();

        if ($environment instanceof Environment) {
            try {
                $outcome = $this->deleteEnvironment->handle($environment, null, $actor);
                $waiting = match ($outcome) {
                    DeletionOutcome::Deleted => null,
                    DeletionOutcome::WaitingForFinalBackup => 'Removal waits for the final backup of its data.',
                    DeletionOutcome::Blocked => 'Removal is blocked: the final backup of its data failed. Retry it or delete without a final backup in the project settings.',
                };
            } catch (DomainRuleViolation) {
                $waiting = 'Removal waits for a running backup or restore of its database.';
            }

            if ($waiting !== null) {
                $preview->forceFill([
                    'data' => [...$preview->data, 'pendingClose' => ['to' => $to->value, 'reason' => $reason]],
                    'status_reason' => $waiting,
                ])->save();

                return;
            }
        }

        DB::transaction(function () use ($preview, $to, $reason, $actor, $environment): void {
            $preview = Preview::query()->whereKey($preview->id)->lockForUpdate()->firstOrFail();

            PreviewShareLink::query()->where('preview_id', $preview->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $preview->environment_id = null;
            $preview->data = [];
            $preview->transitionTo($to, $reason);

            if ($environment instanceof Environment) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::PreviewDestroyed,
                    actor: $actor,
                    teamId: $preview->team_id,
                    subjectType: 'preview',
                    subjectId: $preview->public_id,
                    meta: ['pr' => $preview->pr_number, 'environment' => $environment->public_id, 'status' => $to->value],
                ));
            }
        });

        if ($environment instanceof Environment) {
            dispatch(new ReportPreviewToGitHubJob($preview->id));
            dispatch(new AdvancePreviewsJob($preview->team_id));
        }
    }
}
