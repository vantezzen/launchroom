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
use App\Models\User;
use App\Services\Instance\DashboardOriginSwitch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Step 5 of Settings → Dashboard domain (docs/05 §4.1, D43): makes a ready
 * domain the canonical origin. The HTTPS test must have passed in the last
 * few minutes (it runs again first otherwise). Afterwards:
 *
 * - `app.url`, links, the installer script, the passkey relying party (kept
 *   when the new host is under the old one) and session cookies follow;
 * - the previous origin keeps being routed: browsers are redirected,
 *   launchroomd and (during the transition window) the API, CLI, webhooks
 *   and installer keep working there;
 * - every server re-renders: launchroomd gets the new URL in its signed
 *   NodeState (`system.controlPlane`) and keeps its configured URL as the
 *   fallback.
 *
 * Returns the new origin.
 */
final readonly class SwitchDashboardDomain
{
    public function __construct(
        private RecordAuditEvent $audit,
        private DashboardOriginSwitch $switch,
        private CheckDashboardDomain $check,
    ) {}

    public function handle(User $user, AuditActor $actor): string
    {
        $open = DashboardDomainChange::open();

        if (! $open instanceof DashboardDomainChange) {
            throw DomainRuleViolation::conflict('No new dashboard domain is being set up.');
        }

        if (! $this->fresh($open)) {
            $open = $this->check->handle($open);
        }

        $target = DB::transaction(function () use ($open, $user, $actor): string {
            $settings = InstanceSettings::lockedForUpdate();
            $change = DashboardDomainChange::query()->whereKey($open->id)->lockForUpdate()->firstOrFail();

            if ($change->status !== DashboardDomainStatus::Ready || ! $this->fresh($change)) {
                throw DomainRuleViolation::on('hostname', 'https://'.$change->hostname.' isn’t ready: '.($change->probe['message'] ?? 'the checks haven’t passed yet.'));
            }

            $previous = DashboardDomainChange::active();

            if ($previous instanceof DashboardDomainChange) {
                $previous->transitionTo(DashboardDomainStatus::Reverted, 'replaced');
            }

            $moved = $this->switch->moveTo($settings, 'https://'.$change->hostname);
            $change->forceFill(['switched_by' => $user->id]);
            $change->transitionTo(DashboardDomainStatus::Active);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DashboardDomainSwitched,
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

    private function fresh(DashboardDomainChange $change): bool
    {
        $checkedAt = $change->probe['checkedAt'] ?? null;

        return ($change->probe['ok'] ?? false) === true && is_string($checkedAt)
            && CarbonImmutable::parse($checkedAt)->greaterThan(now()->subSeconds(config()->integer('launchroom.dashboard.probe_fresh_seconds')));
    }
}
