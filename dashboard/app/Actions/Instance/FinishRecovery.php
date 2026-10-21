<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\DB;

/**
 * Leaves recovery mode (docs/14 §5.2): an instance owner confirms the
 * checklist is done. Audited with what was left open.
 */
final readonly class FinishRecovery
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<string>  $open  checklist items still open (for the audit trail)
     */
    public function handle(array $open, AuditActor $actor): void
    {
        DB::transaction(function () use ($open, $actor): void {
            $settings = InstanceSettings::query()->whereKey(InstanceSettings::ID)->lockForUpdate()->firstOrFail();

            if ($settings->recovery_state === null) {
                return;
            }

            $state = $settings->recovery_state;
            $settings->forceFill(['recovery_state' => null])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RecoveryFinished,
                actor: $actor,
                subjectType: 'instance',
                meta: ['startedAt' => $state['startedAt'], 'open' => $open],
            ));
        });
    }
}
