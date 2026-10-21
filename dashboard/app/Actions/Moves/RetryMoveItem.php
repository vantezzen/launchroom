<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\MoveItemStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use Illuminate\Support\Facades\DB;

/**
 * Tries a failed service of a move again (docs/14 §13.2), from the start:
 * a failure never leaves anything half done (before the cutover nothing
 * changed; after it the service went back to the source).
 */
final readonly class RetryMoveItem
{
    public function __construct(
        private RecordAuditEvent $audit,
        private AdvanceServerMoves $advance,
    ) {}

    public function handle(ServerMove $move, ServerMoveItem $item, AuditActor $actor): ServerMoveItem
    {
        DB::transaction(function () use ($move, $item, $actor): void {
            $move = ServerMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();
            $item = ServerMoveItem::query()->whereKey($item->id)->where('server_move_id', $move->id)->lockForUpdate()->firstOrFail();

            if (! in_array($move->status, [ServerMoveStatus::Running, ServerMoveStatus::Ready], true)) {
                throw DomainRuleViolation::conflict('This move has ended.');
            }

            if ($item->status !== MoveItemStatus::Failed) {
                throw DomainRuleViolation::conflict('Only a failed service can be tried again.');
            }

            $item->transitionTo(MoveItemStatus::Pending);

            if ($move->status === ServerMoveStatus::Ready) {
                $move->transitionTo(ServerMoveStatus::Running);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerMoveItemRetried,
                actor: $actor,
                teamId: $move->team_id,
                subjectType: 'server_move',
                subjectId: $move->public_id,
                meta: ['item' => $item->public_id, 'attempts' => $item->attempts],
            ));
        });

        $this->advance->handle($move->id);

        return $item->refresh();
    }
}
