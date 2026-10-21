<?php

declare(strict_types=1);

namespace App\Actions\Instance\DashboardDomain;

use App\Data\Protocol\V1\CertificateStatus;
use App\Models\DashboardDomainChange;
use App\Models\Server;
use Spatie\LaravelData\Optional;

/**
 * The control-plane edge's certificate report for a dashboard domain being
 * set up (Report.ingress.certificates, docs/05 §5): shown as the
 * "Certificate" step with Caddy's last error, so a failing ACME challenge is
 * visible before the HTTPS test explains it.
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

        $change = DashboardDomainChange::open();

        if (! $change instanceof DashboardDomainChange || ! isset($byHost[$change->hostname])) {
            return;
        }

        $report = $byHost[$change->hostname];
        $change->forceFill(['certificate' => array_filter([
            'state' => $report->state,
            'issuer' => $report->issuer instanceof Optional ? null : mb_substr($report->issuer, 0, 256),
            'notAfter' => is_string($report->notAfter) ? $report->notAfter : null,
            'lastError' => $report->lastError instanceof Optional ? null : mb_substr($report->lastError, 0, 1024),
            'reportedAt' => now()->toIso8601ZuluString(),
        ], static fn (?string $value): bool => $value !== null)])->save();
    }
}
