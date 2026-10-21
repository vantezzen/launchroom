<?php

declare(strict_types=1);

namespace App\Actions\Instance\DashboardDomain;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DashboardDomainStatus;
use App\Models\DashboardDomainChange;
use App\Models\InstanceSettings;
use App\Services\Instance\DashboardOrigin;
use App\Services\Instance\DashboardOriginSwitch;
use Illuminate\Support\Facades\DB;

/**
 * The escape hatch (`launchroom:dashboard-domain:reset`, `./launchroom
 * dashboard-domain-reset`, docs/05 §4.1): APP_URL from `.env` becomes the
 * canonical origin again, whatever Settings chose, and a change being set up
 * is dropped. The domain it leaves keeps answering as an earlier origin
 * unless `forgetOld` (then only APP_URL is routed). Also what the installer
 * runs when `--domain` is given again.
 */
final readonly class ResetDashboardDomain
{
    public function __construct(
        private RecordAuditEvent $audit,
        private DashboardOrigin $origin,
        private DashboardOriginSwitch $switch,
    ) {}

    /**
     * @return array{from: string, to: string, rpIdFrom: string|null, rpIdTo: string|null}
     */
    public function handle(AuditActor $actor, bool $forgetOld = false): array
    {
        $moved = DB::transaction(function () use ($actor, $forgetOld): array {
            $settings = InstanceSettings::lockedForUpdate();
            $this->origin->refresh();

            DashboardDomainChange::open()?->transitionTo(DashboardDomainStatus::Cancelled, 'reset');
            DashboardDomainChange::active()?->transitionTo(DashboardDomainStatus::Reverted, 'reset');

            $moved = $this->switch->moveTo($settings, $this->origin->installUrl(), $forgetOld);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DashboardDomainReset,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: [...$moved, 'forgetOld' => $forgetOld],
            ));

            return $moved;
        });

        $this->switch->invalidateServers();

        return $moved;
    }
}
