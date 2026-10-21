<?php

declare(strict_types=1);

namespace App\Actions\Ingress;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\ServerRole;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use App\Models\User;
use App\Services\Ingress\EdgeServers;
use Illuminate\Support\Facades\DB;

/**
 * An instance owner admits an edge to (or removes it from) the install-wide
 * certificate storage and DNS-01 credentials (docs/05 §5, review M2). Team
 * admins can give servers the edge role, but only admitted edges (and the
 * control plane) get these instance secrets; the others serve their team's
 * routes with certificates of their own. Callers authorise `manageInstance`.
 * Every edge re-renders (storage allow-list, sealed credentials).
 */
final readonly class SetSharedEdge
{
    public function __construct(
        private EdgeServers $edges,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Server $server, bool $shared, User $user, AuditActor $actor): Server
    {
        return DB::transaction(function () use ($server, $shared, $user, $actor): Server {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();

            if ($server->is_control_plane) {
                throw DomainRuleViolation::on('shared', 'The control plane always shares the certificates.');
            }

            if ($shared && (! $server->hasRole(ServerRole::Edge) || $server->revoked_at !== null)) {
                throw DomainRuleViolation::on('shared', 'Only edge servers can share the certificates.');
            }

            $server->forceFill([
                'shared_edge_approved_at' => $shared ? now() : null,
                'shared_edge_approved_by' => $shared ? $user->id : null,
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::SharedEdgeChanged,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: ['shared' => $shared],
            ));

            event(new NodeStateInvalidated(array_values(array_unique([$server->id, ...$this->edges->ids()]))));

            return $server;
        });
    }
}
