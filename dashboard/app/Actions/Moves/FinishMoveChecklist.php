<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\DB;

/**
 * Closes the checklist the new dashboard shows after a move (docs/14
 * §13.4), noting what was left open (audited).
 */
final readonly class FinishMoveChecklist
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<string>  $open
     */
    public function handle(array $open, AuditActor $actor): void
    {
        DB::transaction(function () use ($open, $actor): void {
            $settings = InstanceSettings::lockedForUpdate();
            $state = $settings->move_checklist;

            if ($state === null) {
                return;
            }

            $settings->forceFill(['move_checklist' => null])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneMoveFinished,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: array_filter(['move' => $state['moveId'] ?? null, 'open' => array_slice($open, 0, 10)]),
            ));
        });
    }
}
