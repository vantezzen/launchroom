<?php

declare(strict_types=1);

namespace App\Actions\Instance\DashboardDomain;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DashboardDomainStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Instance\CheckDashboardDomainJob;
use App\Models\DashboardDomainChange;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\Instance\DashboardOrigin;
use App\Services\Instance\DashboardOriginSwitch;
use App\Support\Hostname;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of Settings → Dashboard domain (docs/05 §4.1): the owner enters a
 * domain. It must be a DNS name the instance can use for itself: not the
 * current address, not the platform domain or below it (those are apps'
 * generated names, and apps must not share the dashboard's cookies), not a
 * project's custom domain, not sslip.io/nip.io. A change still being set up
 * is replaced. The checks run right away and every minute after.
 */
final readonly class StartDashboardDomainChange
{
    public function __construct(
        private RecordAuditEvent $audit,
        private DashboardOrigin $origin,
        private DashboardOriginSwitch $switch,
    ) {}

    public function handle(string $input, User $user, AuditActor $actor): DashboardDomainChange
    {
        $host = $this->hostname($input);

        $change = DB::transaction(function () use ($host, $user, $actor): DashboardDomainChange {
            $settings = InstanceSettings::lockedForUpdate();
            $this->origin->refresh();

            if ($host === $this->origin->canonicalHost()) {
                throw DomainRuleViolation::on('hostname', 'The dashboard already runs at '.$host.'.');
            }

            $apps = $settings->apps_domain;

            if (is_string($apps) && ($host === $apps || str_ends_with($host, '.'.$apps))) {
                throw DomainRuleViolation::on('hostname', 'Names under the platform domain '.$apps.' belong to apps. Use a name outside it, such as dash.'.$this->parent($apps).'.');
            }

            if (Domain::query()->where('hostname', $host)->exists()) {
                throw DomainRuleViolation::on('hostname', 'A project already uses '.$host.' as a domain. Remove it there first, or pick another name.');
            }

            $open = DashboardDomainChange::open();

            if ($open instanceof DashboardDomainChange) {
                $open->transitionTo(DashboardDomainStatus::Cancelled, 'replaced');
            }

            if ($settings->dashboard_pending_host !== null) {
                $settings->forceFill(['dashboard_pending_host' => null])->save();
            }

            $change = DashboardDomainChange::query()->create([
                'hostname' => $host,
                'previous_url' => $this->origin->canonical(),
                'requested_by' => $user->id,
            ]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DashboardDomainStarted,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['hostname' => $host, 'from' => $this->origin->canonical()],
            ));

            return $change;
        });

        $this->switch->invalidateControlPlane();
        dispatch(new CheckDashboardDomainJob($change->id));

        return $change;
    }

    private function hostname(string $input): string
    {
        $value = trim($input);
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];
        $host = Hostname::normalize($value);

        if (! Hostname::isValid($host) || substr_count($host, '.') < 1) {
            throw DomainRuleViolation::on('hostname', 'Enter a domain such as dash.example.com (without https:// and without a path).');
        }

        if ($host === 'sslip.io' || str_ends_with($host, '.sslip.io') || $host === 'nip.io' || str_ends_with($host, '.nip.io')) {
            throw DomainRuleViolation::on('hostname', 'sslip.io names are launchroom’s automatic fallback. Enter a domain you own.');
        }

        return $host;
    }

    private function parent(string $apps): string
    {
        $dot = strpos($apps, '.');

        return $dot === false || substr_count($apps, '.') < 2 ? $apps : substr($apps, $dot + 1);
    }
}
