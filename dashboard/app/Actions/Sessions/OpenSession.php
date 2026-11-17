<?php

declare(strict_types=1);

namespace App\Actions\Sessions;

use App\Actions\Audit\RecordAuditEvent;
use App\Broadcasting\Channel;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Protocol\V1\Tasks\SessionSpec;
use App\Data\Services\DatabaseSource;
use App\Data\Sessions\OpenedSessionData;
use App\Data\Sessions\OpenSessionData;
use App\Data\Sessions\SessionReplicaData;
use App\Data\Tasks\SessionKeySeal;
use App\Data\Tasks\TaskSeal;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskPurpose;
use App\Enums\ServiceKind;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Enums\TaskBackedAccessStatus;
use App\Enums\WorkloadPhase;
use App\Exceptions\DomainRuleViolation;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Sessions\SessionRelay;
use App\Services\Sessions\SessionSecrets;
use App\Services\Sessions\SessionTargets;
use App\Services\Tasks\DaemonTasks;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Opens a Console, Data or recovery session (docs/03 §11.1): the session
 * row with a single-use ticket and a fresh key, the `session` daemon task
 * pinned to the replica's server (the key is sealed to that server at lease
 * time), and the "started" audit entry. The caller has authorized the user
 * (D60: no password prompt).
 *
 * A recovery shell (OD9) is a terminal like the console, in a copy of a
 * replica whose container won't stay up, with the app not started. Its
 * volumes are read-only unless the user allows changes, which the audit
 * entry records; changes are refused while a container of a service with
 * data still runs, so two processes never write the same volume.
 */
final readonly class OpenSession
{
    public function __construct(
        private SessionTargets $targets,
        private SessionRelay $relay,
        private RecordAuditEvent $audit,
        private EndSession $end,
        private DaemonTasks $tasks,
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
        $this->replacePrevious($user, $instance, $kind);

        $ticket = SessionSecrets::ticket();
        $key = SessionSecrets::key();
        $idle = config()->integer('launchroom.sessions.idle_minutes') * 60;
        $max = config()->integer('launchroom.sessions.max_minutes') * 60;
        $terminal = $kind !== InteractiveSessionKind::Data;
        $allowChanges = $kind === InteractiveSessionKind::Recovery && $data->allowChanges;
        $record = $terminal && $instance->environment->project->team->settings->recordConsoleSessions;

        $session = DB::transaction(function () use ($user, $instance, $kind, $data, $placement, $engine, $ticket, $key, $idle, $max, $record, $actor, $terminal, $allowChanges): InteractiveSession {
            // Serialize this user's opens (FOR UPDATE can't be combined with COUNT).
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            // Sessions already being ended (replaced, closed) don't count.
            $open = InteractiveSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', TaskBackedAccessStatus::nonTerminal())
                ->whereNull('ended_by_id')
                ->count();

            if ($open >= config()->integer('launchroom.sessions.max_open_per_user')) {
                throw DomainRuleViolation::conflict('You have three sessions open already. End one to open another.');
            }

            $publicId = PublicId::generate(InteractiveSession::publicIdPrefix());
            $shell = $terminal ? $data->shell : null;

            $task = $this->tasks->create(
                $instance->team_id,
                new SessionSpec(
                    session: $publicId,
                    kind: $kind->value,
                    workload: $placement->workload_id,
                    channel: Channel::Session->wire($publicId),
                    idleTimeout: intdiv($idle, 60).'m',
                    shell: $shell,
                    cols: $terminal ? $data->cols : null,
                    rows: $terminal ? $data->rows : null,
                    record: $record ? true : null,
                    engine: $engine,
                    allowChanges: $allowChanges ? true : null,
                ),
                $max,
                pin: $placement->server,
                seal: new TaskSeal(sessionKey: new SessionKeySeal($publicId)),
                purpose: DaemonTaskPurpose::forSession($kind),
            );

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
                    'shell' => $shell ?? ($terminal ? 'auto' : null),
                    'engine' => $engine,
                    'recorded' => $record ? true : null,
                    'allowChanges' => $kind === InteractiveSessionKind::Recovery ? $allowChanges : null,
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
     * A new session replaces the user's live ones of the same kind on the
     * same service (docs/03 §11.1): a reconnect never waits for the old
     * session's server to notice the browser left.
     */
    private function replacePrevious(User $user, ServiceInstance $instance, InteractiveSessionKind $kind): void
    {
        InteractiveSession::query()
            ->where('user_id', $user->id)
            ->where('service_instance_id', $instance->id)
            ->where('kind', $kind->value)
            ->whereIn('status', TaskBackedAccessStatus::nonTerminal())
            ->whereNull('ended_by_id')
            ->get()
            ->each(fn (InteractiveSession $previous) => $this->end->handle($previous, $user, 'Replaced by a new session.'));
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
                throw DomainRuleViolation::conflict("Update {$placement->server->name} in Settings → Updates to browse {$source->engine->label()}.");
            }

            return [$placement, $source->engine->value];
        }

        if ($kind === InteractiveSessionKind::Recovery) {
            return [$this->recoveryTarget($instance, $data), null];
        }

        $placement = $this->targets->replica($instance, $data->workload, $kind);

        if (! $placement instanceof Placement) {
            throw DomainRuleViolation::on('workload', $data->workload === null
                ? 'No replica of this service is running on a server that allows consoles right now.'
                : 'That replica isn’t running anymore. Pick another one.');
        }

        return [$placement, null];
    }

    private function recoveryTarget(ServiceInstance $instance, OpenSessionData $data): Placement
    {
        $placement = $this->targets->stuckReplica($instance, $data->workload);

        if (! $placement instanceof Placement) {
            $stuck = $this->targets->stuckReplicas($instance)->first();

            throw match (true) {
                $stuck instanceof Placement => DomainRuleViolation::conflict("Update {$stuck->server->name} in Settings → Updates, or allow recovery shells on it, to open one there."),
                $data->workload !== null => DomainRuleViolation::on('workload', 'That replica is running again. Open a console instead.'),
                default => DomainRuleViolation::conflict('Every replica of this service is running. Open a console instead.'),
            };
        }

        // Two processes writing the same data can corrupt it: while this
        // container still runs between restarts, its volumes stay read-only.
        if ($data->allowChanges && in_array($placement->phase, WorkloadPhase::running(), true) && $this->hasData($instance)) {
            throw DomainRuleViolation::on('allowChanges', 'This service keeps data in a volume and its container is still running between restarts. Open the shell read-only, or stop the service first to allow changes.');
        }

        return $placement;
    }

    private function hasData(ServiceInstance $instance): bool
    {
        return $instance->service->kind->isStateful() || $instance->mountedVolumes()->isNotEmpty();
    }
}
