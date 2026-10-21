<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\ClientCertificatesOverviewData;
use App\Data\Security\ServerCertificateReadinessData;
use App\Enums\ClientCertificateMode;
use App\Enums\DaemonFeature;
use App\Enums\ServerStatus;
use App\Models\DaemonClientCa;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerClientCertificate;
use Carbon\CarbonInterface;

/**
 * Settings → Security → Server certificates (docs/03 §4.3): the instance
 * mode and every server's observed readiness. Readiness is what its last
 * requests showed, never assumed: "ready" means a verified certificate in
 * the last 15 minutes and no request without one since.
 */
final readonly class ClientCertificatesOverview
{
    public function get(): ClientCertificatesOverviewData
    {
        $settings = InstanceSettings::current();
        $mode = $settings->daemon_client_certificates ?? ClientCertificateMode::Off;
        $ca = DaemonClientCa::query()->whereNull('retired_at')->orderByDesc('id')->first();

        $servers = Server::query()
            ->with('team:id,name')
            ->where('status', '!=', ServerStatus::Revoked->value)
            ->orderByDesc('is_control_plane')
            ->orderBy('name')
            ->get();

        $certificates = ServerClientCertificate::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->whereNull('revoked_at')
            ->where('not_after', '>', now())
            ->orderByDesc('not_after')
            ->get()
            ->unique('server_id')
            ->keyBy('server_id');

        $rows = [];

        foreach ($servers as $server) {
            $rows[] = $this->row($server, $certificates->get($server->id), $mode);
        }

        $ready = count(array_filter($rows, static fn (ServerCertificateReadinessData $row): bool => $row->ready));

        return new ClientCertificatesOverviewData(
            mode: $mode->value,
            changedAt: $settings->daemon_client_certificates_changed_at,
            caFingerprint: $ca?->fingerprint,
            caExpiresAt: $ca?->not_after,
            lifetimeDays: intdiv(config()->integer('launchroom.daemon.client_certificates.lifetime_hours'), 24),
            servers: $rows,
            readyCount: $ready,
            blockingCount: count($rows) - $ready,
        );
    }

    /**
     * Servers that would be refused if certificates were required now.
     *
     * @return list<string> names
     */
    public function blocking(): array
    {
        return array_values(array_map(
            static fn (ServerCertificateReadinessData $row): string => $row->name,
            array_filter($this->get()->servers, static fn (ServerCertificateReadinessData $row): bool => ! $row->ready),
        ));
    }

    private function row(Server $server, ?ServerClientCertificate $certificate, ClientCertificateMode $mode): ServerCertificateReadinessData
    {
        $state = $this->state($server, $certificate, $mode);

        return new ServerCertificateReadinessData(
            id: $server->public_id,
            name: $server->name,
            team: $server->team->name,
            state: $state,
            ready: in_array($state, ['ready', 'control_plane'], true),
            fingerprint: $certificate?->fingerprint,
            expiresAt: $certificate?->not_after,
            lastPresentedAt: $server->client_certificate_seen_at,
            lastMissingAt: $server->client_certificate_missing_at,
            reissueUntil: $server->client_certificate_reissue_until?->isFuture() === true ? $server->client_certificate_reissue_until : null,
            canAllowReissue: $mode === ClientCertificateMode::Require && ! $certificate instanceof ServerClientCertificate && ! $server->is_control_plane,
        );
    }

    private function state(Server $server, ?ServerClientCertificate $certificate, ClientCertificateMode $mode): string
    {
        if ($server->is_control_plane) {
            return 'control_plane';
        }

        if (! $server->supportsDaemonFeature(DaemonFeature::ClientCertificates)) {
            return 'update';
        }

        if (in_array($server->status, [ServerStatus::Offline, ServerStatus::Unreachable], true)) {
            return 'offline';
        }

        if (! $certificate instanceof ServerClientCertificate) {
            return 'waiting';
        }

        if (! $mode->checks()) {
            return 'has_certificate';
        }

        $window = now()->subMinutes(config()->integer('launchroom.daemon.client_certificates.ready_within_minutes'));
        $seen = $server->client_certificate_seen_at;
        $missing = $server->client_certificate_missing_at;

        if ($seen instanceof CarbonInterface && $seen->greaterThan($window)
            && ($missing === null || $missing->lessThan($seen) || $missing->lessThan($window))) {
            return 'ready';
        }

        return 'not_presenting';
    }
}
