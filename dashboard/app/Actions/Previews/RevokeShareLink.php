<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Previews\PreviewShareLink;
use Illuminate\Support\Facades\Cache;

/**
 * Revokes a share link: the link and every cookie it minted stop working
 * (cookies re-check the link, cached for at most `access_cache_seconds`).
 */
final readonly class RevokeShareLink
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(PreviewShareLink $link, AuditActor $actor): void
    {
        if ($link->revoked_at !== null) {
            return;
        }

        $link->forceFill(['revoked_at' => now()])->save();
        Cache::forget("launchroom:preview-access:link:{$link->public_id}");

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::PreviewShareLinkRevoked,
            actor: $actor,
            teamId: $link->team_id,
            subjectType: 'preview_share_link',
            subjectId: $link->public_id,
        ));
    }
}
