<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Protocol\V1\EnrollRequest;
use App\Enums\AuditAction;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Team;
use App\Support\Crypto\OpaqueToken;
use App\Support\Network\ServerAddress;
use App\Support\Servers\HostRoleLimits;
use Illuminate\Support\Facades\DB;

/**
 * Registers a server from a signed enrollment request (docs/03 §3).
 *
 * The token is consumed atomically (row lock + `used_at`). A retry with the
 * same token *and the same signing key* returns the already-enrolled server,
 * so a lost response doesn't strand the daemon; any other reuse fails.
 */
final readonly class EnrollServer
{
    public function __construct(
        private RenderNodeState $renderNodeState,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(EnrollRequest $request, ?string $ip): Server
    {
        return DB::transaction(function () use ($request, $ip): Server {
            $enrollment = ServerEnrollment::query()
                ->where('token_hash', OpaqueToken::hash($request->token))
                ->lockForUpdate()
                ->first();

            if ($enrollment === null || ! OpaqueToken::matches($request->token, $enrollment->token_hash)) {
                throw DomainRuleViolation::forbidden('Invalid join token.');
            }

            if ($enrollment->used_at !== null) {
                return $this->retriedEnrollment($enrollment, $request);
            }

            if (! $enrollment->expires_at->isFuture()) {
                throw DomainRuleViolation::forbidden('The join token has expired. Create a new one in the dashboard.');
            }

            if (! in_array('v1', $request->protocolVersions, true)) {
                throw DomainRuleViolation::forbidden('This launchroomd does not speak protocol v1.');
            }

            if (Server::query()->where('signing_public_key', $request->signingKey)->exists()) {
                throw DomainRuleViolation::forbidden('This launchroomd key is already enrolled.');
            }

            $team = Team::query()->findOrFail($enrollment->team_id);
            $facts = $request->host->toStored();
            // A Mac never serves traffic (D53): roles it can't take are dropped, build is always possible.
            $wanted = array_values($enrollment->roles->all());
            $roles = $enrollment->is_control_plane ? $wanted : HostRoleLimits::allowed($wanted, null, $facts);
            $dropped = array_values(array_map(
                static fn (ServerRole $role): string => $role->value,
                array_filter($wanted, static fn (ServerRole $role): bool => ! in_array($role, $roles, true)),
            ));

            $server = Server::query()->create([
                'team_id' => $enrollment->team_id,
                'name' => $enrollment->name ?? $request->host->hostname,
                'roles' => $roles,
                'is_control_plane' => $enrollment->is_control_plane,
                'daemon_version' => $request->daemonVersion,
                'protocol_version' => 'v1',
                'signing_public_key' => $request->signingKey,
                'sealing_public_key' => $request->sealingKey,
                'facts' => $facts,
                // Only a public source address: the control-plane daemon arrives
                // from Docker's bridge gateway. launchroomd reports what IP echo
                // services see right after (RecordReport, docs/05 §5).
                'public_ipv4' => ServerAddress::ipv4($ip),
                'public_ipv6' => ServerAddress::ipv6($ip),
                'enrolled_at' => now(),
            ]);
            $server->forceFill(['last_seen_at' => now()])->save();

            $enrollment->forceFill(['used_at' => now(), 'server_id' => $server->id])->save();

            $actor = AuditActor::server($server, $ip);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerEnrolled,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: [
                    'enrollment' => $enrollment->public_id,
                    'signing_key' => $request->signingKey,
                    'daemon_version' => $request->daemonVersion,
                    ...($dropped === [] ? [] : ['roles_dropped' => $dropped]),
                ],
            ));

            // The control plane's own daemon and teams without "require
            // approval" go live immediately (docs/03 §3).
            if ($server->is_control_plane || ! $team->settings->requireServerApproval) {
                $server->forceFill(['approved_at' => now()]);
                $server->transitionTo(ServerStatus::Online);
                $this->renderNodeState->handle($server);
            }

            return $server;
        });
    }

    private function retriedEnrollment(ServerEnrollment $enrollment, EnrollRequest $request): Server
    {
        $server = $enrollment->server_id === null ? null : Server::query()->find($enrollment->server_id);

        if ($server === null
            || $server->status === ServerStatus::Revoked
            || ! hash_equals($server->signing_public_key, $request->signingKey)
            || ($enrollment->used_at?->diffInMinutes(now()) ?? PHP_INT_MAX) > config()->integer('launchroom.daemon.enrollment_ttl_minutes')) {
            throw DomainRuleViolation::forbidden('This join token has already been used.');
        }

        return $server;
    }
}
