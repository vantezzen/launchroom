<?php

declare(strict_types=1);

namespace App\Actions\Instance\DashboardDomain;

use App\Data\Instance\DashboardProbeData;
use App\Enums\DashboardDomainStatus;
use App\Models\DashboardDomainChange;
use App\Models\InstanceSettings;
use App\Services\Instance\DashboardDomainChecks;
use App\Services\Instance\DashboardOriginSwitch;
use Illuminate\Support\Facades\DB;

/**
 * Steps 2–4 of Settings → Dashboard domain (docs/05 §4.1), run when the
 * change starts, on "Check now" and every minute while it is open:
 *
 * 1. DNS: the name's A/AAAA records point at the control-plane server
 *    (public resolvers, like custom domains).
 * 2. Once they do, the control-plane Caddy routes the name (it joins the
 *    dashboard's system route) and obtains a certificate (HTTP-01 /
 *    TLS-ALPN-01). Routing only after DNS points here keeps Caddy's first
 *    ACME attempt from failing into a long retry back-off.
 * 3. HTTPS: the test request reaches this dashboard with a valid certificate
 *    (DashboardProbe). That also proves the certificate, whatever the
 *    certificate report (RecordDashboardCertificate) says yet.
 *
 * All three passing makes the change `ready`; a later failure sends it back
 * to `pending`. Network calls happen outside the transaction.
 */
final readonly class CheckDashboardDomain
{
    public function __construct(
        private DashboardDomainChecks $checks,
        private DashboardOriginSwitch $switch,
    ) {}

    public function handle(DashboardDomainChange $change): DashboardDomainChange
    {
        if (! $change->status->isOpen()) {
            return $change;
        }

        $dns = $this->checks->dns($change->hostname);
        $dnsOk = $dns->status === 'ok';

        $routed = DB::transaction(function () use ($change, $dnsOk): bool {
            $settings = InstanceSettings::lockedForUpdate();
            $current = DashboardDomainChange::query()->whereKey($change->id)->lockForUpdate()->first();

            if (! $current instanceof DashboardDomainChange || ! $current->status->isOpen()) {
                return false;
            }

            // Once routed, it stays routed until the change ends (no flapping
            // while DNS caches disagree).
            if ($dnsOk && $settings->dashboard_pending_host !== $current->hostname) {
                $settings->forceFill(['dashboard_pending_host' => $current->hostname])->save();

                return true;
            }

            return false;
        });

        if ($routed) {
            $this->switch->invalidateControlPlane();
        }

        $probe = $dnsOk
            ? $this->checks->probe($change->hostname, $dns->addresses)
            : DashboardProbeData::failed('Waiting for DNS.');

        return DB::transaction(function () use ($change, $dns, $dnsOk, $probe): DashboardDomainChange {
            $current = DashboardDomainChange::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();

            if (! $current->status->isOpen()) {
                return $current;
            }

            $current->forceFill([
                'dns' => $dns->toArray(),
                'probe' => $probe->toArray(),
                'checked_at' => now(),
            ]);

            $passed = $dnsOk && $probe->ok;

            if ($passed && $current->status === DashboardDomainStatus::Pending) {
                $current->transitionTo(DashboardDomainStatus::Ready);
            } elseif (! $passed && $current->status === DashboardDomainStatus::Ready) {
                $current->transitionTo(DashboardDomainStatus::Pending);
            } else {
                $current->save();
            }

            return $current;
        });
    }
}
