<?php

declare(strict_types=1);

namespace App\Actions\Daemons;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Daemon\ClientCertificateRequest;
use App\Data\Protocol\V1\ClientCertificate;
use App\Enums\AuditAction;
use App\Enums\ClientCertificateMode;
use App\Exceptions\Daemon\ClientCertificateRefused;
use App\Models\Server;
use App\Models\ServerClientCertificate;
use App\Services\Daemon\ClientCertificateAuthority;
use App\Services\Daemon\ClientCertificatePresence;
use Illuminate\Support\Facades\DB;

/**
 * POST /daemon/v1/client-certificate (mTLS, D50, docs/03 §4.3): signs a
 * client certificate for the public key launchroomd generated. While the
 * instance requires certificates, a server that has had one must present a
 * valid one (renewal) unless an instance owner opened a re-issue window; a
 * server that never had one (a new enrollment) gets its first.
 */
final readonly class IssueClientCertificate
{
    public function __construct(
        private ClientCertificateAuthority $authority,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws ClientCertificateRefused
     */
    public function handle(Server $server, ClientCertificateRequest $request, ClientCertificatePresence $presence, ?string $ip = null): ClientCertificate
    {
        $publicKey = ClientCertificateAuthority::publicKeyPem($request->publicKey)
            ?? throw ClientCertificateRefused::invalidKey();

        return DB::transaction(function () use ($server, $publicKey, $presence, $ip): ClientCertificate {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $this->authorize($server, $presence);

            $recent = ServerClientCertificate::query()
                ->where('server_id', $server->id)
                ->where('created_at', '>', now()->subHour())
                ->count();

            if ($recent >= config()->integer('launchroom.daemon.client_certificates.max_per_hour')) {
                throw ClientCertificateRefused::throttled();
            }

            // Valid from 5 minutes ago (clock skew) for the lifetime; renewed
            // two thirds of the lifetime after it was issued (docs/03 §4.3).
            $lifetime = config()->integer('launchroom.daemon.client_certificates.lifetime_hours') * 3600;
            $issuedAt = now()->toImmutable()->startOfSecond();
            $notBefore = $issuedAt->subMinutes(5);
            $notAfter = $issuedAt->addSeconds($lifetime);
            $renewAfter = $issuedAt->addSeconds(intdiv($lifetime * 2, 3));

            $issued = $this->authority->issue($server, $publicKey, $notBefore, $notAfter);

            ServerClientCertificate::query()->create([
                'server_id' => $server->id,
                'ca_id' => $issued->caId,
                'fingerprint' => $issued->fingerprint,
                'serial' => $issued->serial,
                'not_before' => $issued->notBefore,
                'not_after' => $issued->notAfter,
                'created_at' => now(),
            ]);

            if ($server->client_certificate_reissue_until !== null) {
                $server->forceFill(['client_certificate_reissue_until' => null])->save();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ClientCertificateIssued,
                actor: AuditActor::server($server, $ip),
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: [
                    'fingerprint' => $issued->fingerprint,
                    'serial' => $issued->serial,
                    'notAfter' => $issued->notAfter->toIso8601ZuluString(),
                    'renewal' => $presence->verified(),
                ],
            ));

            return new ClientCertificate(
                serverId: $server->public_id,
                certificate: base64_encode($issued->der),
                caCertificates: $this->authority->trusted(),
                notBefore: $issued->notBefore,
                notAfter: $issued->notAfter,
                renewAfter: $renewAfter,
            );
        });
    }

    /**
     * @throws ClientCertificateRefused
     */
    private function authorize(Server $server, ClientCertificatePresence $presence): void
    {
        if (ClientCertificateMode::current() !== ClientCertificateMode::Require || $presence->verified()) {
            return;
        }

        // The control-plane server's own launchroomd talks over loopback.
        if ($server->is_control_plane && ! $presence->throughEdge) {
            return;
        }

        if ($server->client_certificate_reissue_until?->isFuture() === true) {
            return;
        }

        $hadOne = ServerClientCertificate::query()->where('server_id', $server->id)->exists();

        if ($hadOne) {
            throw ClientCertificateRefused::certificateRequired();
        }
    }
}
