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
 * "Switch back" (docs/05 §4.1): the origin the dashboard ran at before the
 * active domain becomes canonical again. The domain it leaves keeps
 * answering like any earlier origin (redirects; launchroomd's fallback for
 * servers that adopted it), so nothing loses the dashboard on the way back.
 */
final readonly class SwitchBackDashboardDomain
{
    public function __construct(
        private RecordAuditEvent $audit,
        private DashboardOriginSwitch $switch,
    ) {}

    public function handle(AuditActor $actor): string
    {
        $target = DB::transaction(function () use ($actor): string {
            $settings = InstanceSettings::lockedForUpdate();
            $active = DashboardDomainChange::query()->where('status', DashboardDomainStatus::Active->value)->lockForUpdate()->first();

            if (! $active instanceof DashboardDomainChange) {
                throw DomainRuleViolation::conflict('The dashboard isn’t running at a domain set up here, so there is nothing to switch back from.');
            }

            $active->transitionTo(DashboardDomainStatus::Reverted, 'switched_back');
            $moved = $this->switch->moveTo($settings, $active->previous_url);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DashboardDomainReverted,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: $moved,
            ));

            return $moved['to'];
        });

        $this->switch->invalidateServers();

        return $target;
    }
}
