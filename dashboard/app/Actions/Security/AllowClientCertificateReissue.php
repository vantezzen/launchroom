<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * While certificates are required, a server whose certificate expired (it
 * was offline longer than the lifetime) can't renew it on its own. An
 * instance owner opens a one-hour window in which it may get a new one
 * with its signature alone (docs/03 §4.3).
 */
final readonly class AllowClientCertificateReissue
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * Any team's server: only instance owners get here (the caller authorizes).
     */
    public function handle(string $serverPublicId, AuditActor $actor): Server
    {
        return DB::transaction(function () use ($serverPublicId, $actor): Server {
            $server = Server::query()->where('public_id', $serverPublicId)->lockForUpdate()->firstOrFail();

            if ($server->status === ServerStatus::Revoked) {
                throw DomainRuleViolation::on('server', 'This server was removed.');
            }

            $until = now()->addMinutes(config()->integer('launchroom.daemon.client_certificates.reissue_window_minutes'));
            $server->forceFill(['client_certificate_reissue_until' => $until])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ClientCertificateReissueAllowed,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: ['until' => $until->toIso8601ZuluString()],
            ));

            return $server;
        });
    }
}
