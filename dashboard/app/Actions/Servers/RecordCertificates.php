<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Instance\DashboardDomain\RecordDashboardCertificate;
use App\Data\Ingress\IngressReportData;
use App\Data\Instance\DashboardCertificateData;
use App\Data\Protocol\V1\CertificateStatus;
use App\Data\Protocol\V1\IngressStatus;
use App\Enums\CertFailureKind;
use App\Enums\CertStatus;
use App\Enums\DomainKind;
use App\Events\NodeStateInvalidated;
use App\Models\Domain;
use App\Models\Server;
use App\Services\Instance\DashboardOrigin;
use App\Support\Hostname;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Optional;

/**
 * Stores what an edge reports about its ingress (Report.ingress, docs/05 §5):
 * a whitelisted summary on the server (route count, certificate storage
 * state) and, per routed host that is one of the team's domains, the
 * certificate state (issuer, validity, last error). Hosts of other teams are
 * ignored: a server only reports on its own team's routes.
 *
 * Several edges report the same hosts. An edge's report replaces the stored
 * state unless another edge recently (≤ 15 min) reported it issued and this
 * one doesn't (e.g. a new edge that hasn't loaded the shared certificate
 * yet). A custom domain whose "issued" state flips re-renders the team's
 * edges, which add or drop its HSTS header (App\Services\NodeStates\EdgeRouting).
 *
 * The control-plane edge also keeps the certificate of the dashboard's own
 * domain once it is switched to one (`dashboardCertificate`), so its expiry
 * is alerted like any domain's.
 *
 * Runs inside RecordReport's transaction (the server row is locked).
 */
final readonly class RecordCertificates
{
    private const int TRUST_OTHER_EDGE_MINUTES = 15;

    public function __construct(
        private RecordDashboardCertificate $dashboardCertificate,
        private DashboardOrigin $origin,
    ) {}

    public function handle(Server $server, IngressStatus $ingress): void
    {
        /** @var array<string, CertificateStatus> $byHost */
        $byHost = [];

        foreach (is_array($ingress->certificates) ? $ingress->certificates : [] as $certificate) {
            $byHost[$certificate->host] = $certificate;
        }

        $previous = $server->ingress_status;
        $status = IngressReportData::fromProtocol($ingress, $previous);
        [$status->dashboardHost, $status->dashboardCertificate] = $this->dashboardDomain($server, $byHost) ?? [$previous?->dashboardHost, $previous?->dashboardCertificate];

        $server->forceFill([
            'ingress_status' => $status,
            'ingress_status_at' => now(),
        ])->save();

        if ($byHost === []) {
            return;
        }

        // A dashboard domain being set up on the control plane (docs/05 §4.1).
        $this->dashboardCertificate->handle($server, $byHost);

        $domains = Domain::query()
            ->where('team_id', $server->team_id)
            ->whereIn('hostname', array_keys($byHost))
            ->lockForUpdate()
            ->get();

        $flipped = false;

        foreach ($domains as $domain) {
            $report = $byHost[$domain->hostname];
            $state = $report->state;
            $wasIssued = $domain->cert_status === CertStatus::Issued;

            if ($wasIssued && $state !== CertStatus::Issued && $domain->cert_server_id !== null && $domain->cert_server_id !== $server->id
                && $domain->cert_checked_at !== null && $domain->cert_checked_at->greaterThan(now()->subMinutes(self::TRUST_OTHER_EDGE_MINUTES))) {
                continue;
            }

            $domain->forceFill([
                'cert_status' => $state,
                'cert_issuer' => $report->issuer instanceof Optional ? null : mb_substr($report->issuer, 0, 256),
                'cert_not_before' => $this->time($report->notBefore),
                'cert_expires_at' => $this->time($report->notAfter),
                'cert_wildcard' => $report->wildcard === true,
                'cert_error' => $report->lastError instanceof Optional ? null : mb_substr($report->lastError, 0, 1024),
                'cert_error_at' => $this->time($report->lastErrorAt),
                // Why the last attempt failed, and the certificate authority's next allowed try.
                'cert_failure_kind' => $state === CertStatus::Issued || ! $report->failureKind instanceof CertFailureKind ? null : $report->failureKind->value,
                'cert_retry_after' => $state === CertStatus::Issued ? null : $this->time($report->retryAfter),
                'cert_checked_at' => now(),
                'cert_server_id' => $server->id,
            ])->save();

            if ($domain->kind === DomainKind::Custom && $domain->hsts && $wasIssued !== ($state === CertStatus::Issued)) {
                $flipped = true;
            }
        }

        if ($flipped) {
            NodeStateInvalidated::edges($server->team_id);
        }
    }

    /**
     * The certificate of the dashboard's own domain, as the control-plane
     * edge reports it, once the dashboard was switched to a public domain.
     *
     * @param  array<string, CertificateStatus>  $byHost
     * @return array{0: string, 1: DashboardCertificateData}|null
     */
    private function dashboardDomain(Server $server, array $byHost): ?array
    {
        $host = $server->is_control_plane && $this->origin->isSwitched() ? $this->origin->canonicalHost() : null;
        $report = $host !== null && ! Hostname::isLocal($host) ? ($byHost[$host] ?? null) : null;

        if ($host === null || ! $report instanceof CertificateStatus) {
            return null;
        }

        return [$host, new DashboardCertificateData(
            state: $report->state,
            issuer: $report->issuer instanceof Optional ? null : mb_substr($report->issuer, 0, 256),
            notAfter: is_string($report->notAfter) ? $report->notAfter : null,
            lastError: $report->lastError instanceof Optional ? null : mb_substr($report->lastError, 0, 1024),
            reportedAt: now()->toIso8601ZuluString(),
        )];
    }

    private function time(string|null|Optional $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
    }
}
