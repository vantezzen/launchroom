<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\DatabaseSource;
use App\Data\Sessions\OpenedSessionData;
use App\Data\Sessions\OpenSessionData;
use App\Data\Sessions\SessionReplicaData;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\DatabaseEngine;
use App\Enums\ServiceKind;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Sessions\SessionRelay;
use App\Services\Sessions\SessionSecrets;
use App\Services\Sessions\SessionTargets;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Opens a Console or Data session (docs/03 §11.1): the session row with a
 * single-use ticket and a fresh key, the `session` daemon task pinned to the
 * replica's server (the key is sealed to that server at lease time), and the
 * "started" audit entry. The caller has authorized the user and confirmed
 * their password.
 */
final readonly class OpenSession
{
    public function __construct(
        private SessionTargets $targets,
        private SessionRelay $relay,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, ServiceInstance $instance, InteractiveSessionKind $kind, OpenSessionData $data, AuditActor $actor): OpenedSessionData
    {
        if (! $this->relay->available()) {
            throw DomainRuleViolation::conflict(config('launchroom.sessions.enabled') === true
                ? 'Live sessions need realtime updates (Reverb), which are off on this launchroom.'
                : 'Live sessions are turned off on this launchroom.');
        }

        $instance->loadMissing('service', 'environment.project');
        [$placement, $engine] = $this->target($instance, $kind, $data);

        $ticket = SessionSecrets::ticket();
        $key = SessionSecrets::key();
        $idle = config()->integer('launchroom.sessions.idle_minutes') * 60;
        $max = config()->integer('launchroom.sessions.max_minutes') * 60;
        $record = $kind === InteractiveSessionKind::Console && $instance->environment->project->team->settings->recordConsoleSessions;

        $session = DB::transaction(function () use ($user, $instance, $kind, $data, $placement, $engine, $ticket, $key, $idle, $max, $record, $actor): InteractiveSession {
            // Serialize this user's opens (FOR UPDATE can't be combined with COUNT).
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            $open = InteractiveSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [InteractiveSessionStatus::Pending->value, InteractiveSessionStatus::Open->value])
                ->count();

            if ($open >= config()->integer('launchroom.sessions.max_open_per_user')) {
                throw DomainRuleViolation::conflict('You have three sessions open already. End one to open another.');
            }

            $publicId = PublicId::generate(InteractiveSession::publicIdPrefix());
            $shell = $kind === InteractiveSessionKind::Console ? $data->shell : null;

            $task = DaemonTask::query()->create([
                'team_id' => $instance->team_id,
                'server_id' => $placement->server_id,
                'pinned_to_server' => true,
                'type' => DaemonTaskType::Session,
                'spec' => (object) array_filter([
                    'session' => $publicId,
                    'kind' => $kind->value,
                    'workload' => $placement->workload_id,
                    'channel' => 'private-session.'.$publicId,
                    'idleTimeout' => intdiv($idle, 60).'m',
                    'shell' => $shell,
                    'cols' => $kind === InteractiveSessionKind::Console ? $data->cols : null,
                    'rows' => $kind === InteractiveSessionKind::Console ? $data->rows : null,
                    'record' => $record ? true : null,
                    'engine' => $engine,
                ], static fn (mixed $value): bool => $value !== null),
                'max_attempts' => 1,
                'timeout_seconds' => $max,
                'purpose' => $kind->value,
                'seal' => ['sessionKey' => ['session' => $publicId]],
            ]);

            $session = new InteractiveSession([
                'team_id' => $instance->team_id,
                'project_id' => $instance->environment->project_id,
                'environment_id' => $instance->environment_id,
                'service_instance_id' => $instance->id,
                'server_id' => $placement->server_id,
                'user_id' => $user->id,
                'kind' => $kind,
                'workload_id' => $placement->workload_id,
                'slot' => $placement->slot,
                'shell' => $shell,
                'engine' => $engine === null ? null : DatabaseWorkloads::source($instance)?->engine,
                'ticket_hash' => SessionSecrets::hash($ticket),
                'ticket_expires_at' => now()->addSeconds(config()->integer('launchroom.sessions.ticket_seconds')),
                'session_key' => $key,
                'record' => $record,
                'idle_seconds' => $idle,
                'max_seconds' => $max,
            ]);
            $session->public_id = $publicId;
            $session->daemon_task_id = $task->id;
            $session->save();

            $this->audit->handle(new AuditEntryData(
                action: $kind->startedAction(),
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter([
                    'session' => $publicId,
                    'environment' => $instance->environment->public_id,
                    'replica' => $placement->slot + 1,
                    'workload' => $placement->workload_id,
                    'server' => $placement->server->public_id,
                    'shell' => $shell ?? ($kind === InteractiveSessionKind::Console ? 'auto' : null),
                    'engine' => $engine,
                    'recorded' => $record ? true : null,
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $session;
        });

        return new OpenedSessionData(
            id: $session->public_id,
            kind: $kind,
            channel: $session->channelName(),
            ticket: $ticket,
            key: $key,
            idleSeconds: $idle,
            maxSeconds: $max,
            record: $record,
            replica: SessionReplicaData::fromPlacement($placement),
            shell: $session->shell,
            engine: $engine,
        );
    }

    /**
     * @return array{Placement, string|null} the replica and the daemon engine name (data sessions)
     */
    private function target(ServiceInstance $instance, InteractiveSessionKind $kind, OpenSessionData $data): array
    {
        if ($kind === InteractiveSessionKind::Data) {
            $source = $instance->service->kind === ServiceKind::Database ? $this->targets->database($instance) : null;

            if (! $source instanceof DatabaseSource) {
                throw DomainRuleViolation::on('service', 'Only databases have a Data tab.');
            }

            $placement = DatabaseWorkloads::servingPlacement($instance);

            if (! $placement instanceof Placement || ! $this->targets->replica($instance, $placement->workload_id, $kind) instanceof Placement) {
                throw DomainRuleViolation::conflict($source->engine->label().' isn’t running right now. Deploy it first.');
            }

            $feature = $source->engine->daemonFeature();

            if ($feature instanceof DaemonFeature && ! $placement->server->supportsDaemonFeature($feature)) {
                throw DomainRuleViolation::conflict("Update launchroomd on {$placement->server->name} to browse {$source->engine->label()}.");
            }

            return [$placement, $this->daemonEngine($source->engine)];
        }

        $placement = $this->targets->replica($instance, $data->workload, $kind);

        if (! $placement instanceof Placement) {
            throw DomainRuleViolation::on('workload', $data->workload === null
                ? 'No replica of this service is running on a server that allows consoles right now.'
                : 'That replica isn’t running anymore. Pick another one.');
        }

        return [$placement, null];
    }

    private function daemonEngine(DatabaseEngine $engine): string
    {
        return $engine->value;
    }
}
