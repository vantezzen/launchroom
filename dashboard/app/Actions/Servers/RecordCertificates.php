<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Instance\DashboardDomain\RecordDashboardCertificate;
use App\Data\Protocol\V1\CertificateStatus;
use App\Data\Protocol\V1\CertStorageStatus;
use App\Data\Protocol\V1\IngressStatus;
use App\Enums\CertStatus;
use App\Enums\DomainKind;
use App\Events\NodeStateInvalidated;
use App\Models\Domain;
use App\Models\Server;
use App\Services\Ingress\EdgeServers;
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
 * Runs inside RecordReport's transaction (the server row is locked).
 */
final readonly class RecordCertificates
{
    private const int TRUST_OTHER_EDGE_MINUTES = 15;

    public function __construct(
        private EdgeServers $edges,
        private RecordDashboardCertificate $dashboardCertificate,
    ) {}

    public function handle(Server $server, IngressStatus $ingress): void
    {
        $storage = $ingress->storage instanceof CertStorageStatus ? $ingress->storage : null;
        $server->forceFill([
            'ingress_status' => array_filter([
                'state' => $ingress->state,
                'routes' => $ingress->routes,
                'storage' => $storage instanceof CertStorageStatus ? array_filter([
                    'state' => $storage->state,
                    'mirrored' => $storage->mirrored,
                    'message' => $storage->message instanceof Optional ? null : mb_substr($storage->message, 0, 1024),
                ], static fn (mixed $value): bool => $value !== null) : null,
            ], static fn (mixed $value): bool => $value !== null),
            'ingress_status_at' => now(),
        ])->save();

        if (! is_array($ingress->certificates) || $ingress->certificates === []) {
            return;
        }

        /** @var array<string, CertificateStatus> $byHost */
        $byHost = [];

        foreach ($ingress->certificates as $certificate) {
            $byHost[$certificate->host] = $certificate;
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
            $state = CertStatus::from($report->state);
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
                'cert_checked_at' => now(),
                'cert_server_id' => $server->id,
            ])->save();

            if ($domain->kind === DomainKind::Custom && $domain->hsts && $wasIssued !== ($state === CertStatus::Issued)) {
                $flipped = true;
            }
        }

        if ($flipped) {
            event(new NodeStateInvalidated($this->edges->ids($server->team_id)));
        }
    }

    private function time(string|null|Optional $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value)->utc() : null;
    }
}
