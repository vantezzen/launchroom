<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Alerts\AlertChannel;
use Illuminate\Support\Facades\DB;

/**
 * Removes a channel and its delivery history; its secrets go with the row.
 */
final readonly class DeleteAlertChannel
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AlertChannel $channel, AuditActor $actor): void
    {
        DB::transaction(function () use ($channel, $actor): void {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AlertChannelDeleted,
                actor: $actor,
                teamId: $channel->team_id,
                subjectType: 'alert_channel',
                subjectId: $channel->public_id,
                meta: ['kind' => $channel->kind->value],
            ));

            $channel->delete();
        });
    }
}
