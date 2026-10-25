<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Previews\PreviewStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Previews\Preview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A maintainer approves a fork's preview (docs/09 R20) for the head commit
 * they reviewed: `$headSha` must still be the preview's head under the row
 * lock, otherwise the fork pushed in between and the approval is refused.
 * The preview still gets no secrets; new commits from the fork need a new
 * approval.
 */
final readonly class ApprovePreview
{
    public function __construct(
        private StartPreview $start,
        private DeployPreview $deploy,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Preview $preview, string $headSha, User $user, AuditActor $actor): Preview
    {
        $hasEnvironment = DB::transaction(function () use ($preview, $headSha, $user, $actor): bool {
            $preview = Preview::query()->whereKey($preview->id)->lockForUpdate()->firstOrFail();

            if ($preview->status !== PreviewStatus::AwaitingApproval) {
                throw DomainRuleViolation::conflict('This preview isn’t waiting for an approval.');
            }

            if (! hash_equals($preview->head_sha, strtolower($headSha))) {
                throw DomainRuleViolation::conflict('New commits were pushed to this pull request. Review them and approve again.');
            }

            $preview->forceFill(['approved_by' => $user->id, 'approved_at' => now(), 'approved_sha' => $preview->head_sha])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::PreviewApproved,
                actor: $actor,
                teamId: $preview->team_id,
                subjectType: 'preview',
                subjectId: $preview->public_id,
                meta: ['pr' => $preview->pr_number, 'sha' => $preview->head_sha, 'author' => $preview->author_login],
            ));

            if ($preview->environment_id !== null) {
                $preview->transitionTo(PreviewStatus::Preparing, 'Approved. Deploying the new commits.');

                return true;
            }

            $preview->transitionTo(PreviewStatus::Queued, 'Approved.');

            return false;
        });

        $hasEnvironment ? $this->deploy->handle($preview) : $this->start->handle($preview);

        return $preview->refresh();
    }
}
