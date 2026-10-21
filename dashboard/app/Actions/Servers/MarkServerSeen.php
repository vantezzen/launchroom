<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerClientCertificate;
use App\Services\Daemon\ClientCertificatePresence;
use Illuminate\Support\Facades\DB;

/**
 * Liveness (docs/03 §9): every signed request counts as a heartbeat and
 * brings an unreachable/offline server back online. While the instance
 * checks client certificates it also records what the request showed
 * (readiness, docs/03 §4.3).
 */
final class MarkServerSeen
{
    /** A certificate's last use is written at most this often. */
    private const int PRESENTED_RESOLUTION_SECONDS = 60;

    public function handle(Server $server, ?ClientCertificatePresence $presence = null): void
    {
        DB::transaction(function () use ($server, $presence): void {
            $server->forceFill(['last_seen_at' => now()]);
            $this->fillClientCertificate($server, $presence);

            if (in_array($server->status, [ServerStatus::Unreachable, ServerStatus::Offline], true)) {
                $server->transitionTo(ServerStatus::Online);

                return;
            }

            $server->save();
        });
    }

    private function fillClientCertificate(Server $server, ?ClientCertificatePresence $presence): void
    {
        if (! $presence instanceof ClientCertificatePresence || ! $presence->checked) {
            return;
        }

        $certificate = $presence->certificate;

        if (! $certificate instanceof ServerClientCertificate) {
            $server->forceFill(['client_certificate_missing_at' => now()]);

            return;
        }

        $server->forceFill(['client_certificate_seen_at' => now()]);

        if ($certificate->last_presented_at === null || $certificate->last_presented_at->diffInSeconds(now()) >= self::PRESENTED_RESOLUTION_SECONDS) {
            $certificate->forceFill(['last_presented_at' => now()])->save();
        }
    }
}
