<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Previews\ShareLinkInput;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewShareLink;
use App\Models\User;

/**
 * A share link for a protected preview (docs/06 §5): anyone with the link
 * can open the preview until it expires (≤ 30 days), is revoked, or the
 * preview is torn down. The link's token is signed and names this row.
 */
final readonly class CreateShareLink
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Preview $preview, ShareLinkInput $input, User $user, AuditActor $actor): PreviewShareLink
    {
        if ($preview->environment_id === null || ! $preview->status->isOpen()) {
            throw DomainRuleViolation::on('preview', 'This preview has no running environment to share.');
        }

        $days = min($input->days, config()->integer('launchroom.previews.share_link_max_days'));
        $label = $input->label === null ? null : trim($input->label);

        $link = PreviewShareLink::query()->create([
            'team_id' => $preview->team_id,
            'preview_id' => $preview->id,
            'label' => $label === '' ? null : $label,
            'created_by' => $user->id,
            'expires_at' => now()->addDays($days),
        ]);

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::PreviewShareLinkCreated,
            actor: $actor,
            teamId: $preview->team_id,
            subjectType: 'preview',
            subjectId: $preview->public_id,
            meta: ['link' => $link->public_id, 'days' => $days],
        ));

        return $link;
    }
}
