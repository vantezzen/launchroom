<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DaemonTaskStatus;
use App\Enums\ServerStatus;
use App\Events\NodeStateInvalidated;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServerClientCertificate;
use App\Queries\MeshServers;
use Illuminate\Support\Facades\DB;

/**
 * Removes a server's trust (docs/09 R25). Its key is denied from the next
 * request on: AuthenticateDaemon only accepts non-revoked servers. Tasks it
 * holds go back to the queue (or are cancelled if pinned to it).
 *
 * Its client certificates are revoked with it (docs/03 §4.3).
 *
 * Its Headscale node is deleted after commit (ServerStatusChanged →
 * SyncMeshMembership → RemoveMeshNodeJob, docs/05 §2).
 *
 * Registry tokens stop with the key: AuthenticateDaemon refuses its
 * registry-token requests, and tokens issued before expire within
 * RegistryTokenIssuer::MAX_TTL_SECONDS. Prompting rotation of the secrets it
 * held is not built yet (docs/12 backlog).
 */
final readonly class RevokeServer
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Server $server, AuditActor $actor): void
    {
        DB::transaction(function () use ($server, $actor): void {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            $server->forceFill(['revoked_at' => now()]);
            $server->transitionTo(ServerStatus::Revoked);

            $tasks = DaemonTask::query()
                ->where('server_id', $server->id)
                ->whereIn('status', [DaemonTaskStatus::Pending->value, DaemonTaskStatus::Leased->value])
                ->lockForUpdate()
                ->get();

            foreach ($tasks as $task) {
                if ($task->pinned_to_server) {
                    $task->transitionTo(DaemonTaskStatus::Cancelled);

                    continue;
                }

                $task->forceFill(['server_id' => null, 'lease_expires_at' => null]);
                $task->transitionTo(DaemonTaskStatus::Pending);
            }

            // Its client certificates stop working from the next request (docs/03 §4.3).
            $revoked = ServerClientCertificate::query()
                ->where('server_id', $server->id)
                ->whereNull('revoked_at')
                ->where('not_after', '>', now())
                ->update(['revoked_at' => now(), 'revoked_reason' => 'server_revoked']);

            // Its mesh address leaves every allow-list, ambassador and edge upstream (docs/05 §3).
            event(new NodeStateInvalidated(MeshServers::ids()));

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerRevoked,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
            ));

            if ($revoked > 0) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::ClientCertificatesRevoked,
                    actor: $actor,
                    teamId: $server->team_id,
                    subjectType: 'server',
                    subjectId: $server->public_id,
                    meta: ['certificates' => $revoked, 'reason' => 'server_revoked'],
                ));
            }
        });
    }
}
