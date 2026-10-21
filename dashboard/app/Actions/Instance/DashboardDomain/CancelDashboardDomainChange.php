<?php

declare(strict_types=1);

namespace App\Actions\Instance\DashboardDomain;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DashboardDomainStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\DashboardDomainChange;
use App\Models\InstanceSettings;
use App\Services\Instance\DashboardOriginSwitch;
use Illuminate\Support\Facades\DB;

/**
 * Drops a dashboard domain that is still being set up (docs/05 §4.1); the
 * control-plane Caddy stops routing it.
 */
final readonly class CancelDashboardDomainChange
{
    public function __construct(
        private RecordAuditEvent $audit,
        private DashboardOriginSwitch $switch,
    ) {}

    public function handle(AuditActor $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $settings = InstanceSettings::lockedForUpdate();
            $change = DashboardDomainChange::open();

            if (! $change instanceof DashboardDomainChange) {
                throw DomainRuleViolation::conflict('No new dashboard domain is being set up.');
            }

            $change->transitionTo(DashboardDomainStatus::Cancelled, 'cancelled');
            $settings->forceFill(['dashboard_pending_host' => null])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DashboardDomainCancelled,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['hostname' => $change->hostname],
            ));
        });

        $this->switch->invalidateControlPlane();
    }
}
