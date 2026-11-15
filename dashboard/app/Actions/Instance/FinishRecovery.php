<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\InstanceSettings;
use App\Queries\RecoveryChecklist;
use App\Services\Instance\RecoveryMode;
use Illuminate\Support\Facades\DB;

/**
 * Leaves recovery mode (docs/14 §5.2): an instance owner confirms the
 * checklist is done. Audited with the steps still open, as freshly checked
 * here.
 */
final readonly class FinishRecovery
{
    public function __construct(
        private RecordAuditEvent $audit,
        private RecoveryChecklist $checklist,
    ) {}

    public function handle(AuditActor $actor): void
    {
        if (! RecoveryMode::active()) {
            return;
        }

        $open = $this->checklist->get(checkImages: true)->openSteps;

        DB::transaction(function () use ($open, $actor): void {
            $settings = InstanceSettings::lockedForUpdate();

            if ($settings->recovery_state === null) {
                return;
            }

            $state = $settings->recovery_state;
            $settings->forceFill(['recovery_state' => null])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RecoveryFinished,
                actor: $actor,
                subjectType: 'instance',
                meta: ['startedAt' => $state->startedAt, 'open' => $open],
            ));
        });
    }
}
