<?php

declare(strict_types=1);

namespace App\Actions\Instance\DashboardDomain;

use App\Data\Instance\DashboardCertificateData;
use App\Data\Protocol\V1\CertificateStatus;
use App\Enums\DashboardDomainStatus;
use App\Models\DashboardDomainChange;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Optional;

/**
 * The control-plane edge's certificate report for a dashboard domain being
 * set up (Report.ingress.certificates, docs/05 §5): shown as the
 * "Certificate" step with Caddy's last error, so a failing ACME challenge is
 * visible before the HTTPS test explains it. After the switch, the active
 * domain's certificate is recorded the same way (renewals, expiry).
 */
final readonly class RecordDashboardCertificate
{
    /**
     * @param  array<string, CertificateStatus>  $byHost
     */
    public function handle(Server $server, array $byHost): void
    {
        if (! $server->is_control_plane) {
            return;
        }

        // The domain being set up, else the one the dashboard runs at: its
        // certificate keeps being tracked after the switch (G38), for
        // Settings → System, `launchroom:doctor` and the expiry alert.
        $change = DashboardDomainChange::open() ?? DashboardDomainChange::active();

        if (! $change instanceof DashboardDomainChange || ! isset($byHost[$change->hostname])) {
            return;
        }

        $report = $byHost[$change->hostname];
        $certificate = new DashboardCertificateData(
            state: $report->state,
            issuer: $report->issuer instanceof Optional ? null : mb_substr($report->issuer, 0, 256),
            notAfter: is_string($report->notAfter) ? $report->notAfter : null,
            lastError: $report->lastError instanceof Optional ? null : mb_substr($report->lastError, 0, 1024),
            reportedAt: now()->toIso8601ZuluString(),
        );
        $previous = $change->certificate;

        // Every report carries it: the active domain's row is only written
        // when something changed (or hourly, so `reportedAt` stays recent).
        if ($change->status === DashboardDomainStatus::Active && $previous instanceof DashboardCertificateData
            && $previous->state === $certificate->state && $previous->notAfter === $certificate->notAfter
            && $previous->issuer === $certificate->issuer && $previous->lastError === $certificate->lastError
            && is_string($previous->reportedAt) && CarbonImmutable::parse($previous->reportedAt)->greaterThan(now()->subHour())) {
            return;
        }

        $change->forceFill(['certificate' => $certificate])->save();
    }
}
