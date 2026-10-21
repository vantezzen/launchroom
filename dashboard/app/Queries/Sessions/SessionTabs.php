<?php

declare(strict_types=1);

namespace App\Queries\Sessions;

use App\Data\Services\DatabaseSource;
use App\Data\Sessions\ConsoleTabData;
use App\Data\Sessions\DataTabData;
use App\Data\Sessions\OpenSessionData;
use App\Data\Sessions\QueryHistoryItemData;
use App\Data\Sessions\SessionHistoryItemData;
use App\Data\Sessions\SessionReplicaData;
use App\Enums\DaemonFeature;
use App\Enums\Permission;
use App\Enums\Sessions\InteractiveSessionKind;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\Sessions\DatabaseQuery;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Sessions\SessionRelay;
use App\Services\Sessions\SessionTargets;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Read models of the Console and Data tabs (docs/08 §15).
 */
final readonly class SessionTabs
{
    private const int RECENT = 10;

    public function __construct(
        private SessionTargets $targets,
        private SessionRelay $relay,
        private ProjectKeyring $keyring,
    ) {}

    public function console(ServiceInstance $instance, User $user, bool $passwordRequired): ConsoleTabData
    {
        $replicas = $this->targets->replicas($instance, InteractiveSessionKind::Console);
        $instance->loadMissing('environment.project.team');

        return new ConsoleTabData(
            available: $this->relay->available() && $replicas->isNotEmpty(),
            unavailableReason: $this->unavailable($replicas->isNotEmpty(), 'No replica of this service is running on a server that allows consoles. Deploy it, or check the server’s LR_INTERACTIVE_SESSIONS setting.'),
            replicas: array_values($replicas->map(static fn (Placement $placement): SessionReplicaData => SessionReplicaData::fromPlacement($placement))->all()),
            shells: ['', ...OpenSessionData::SHELLS],
            record: $instance->environment->project->team->settings->recordConsoleSessions,
            passwordRequired: $passwordRequired,
            idleMinutes: config()->integer('launchroom.sessions.idle_minutes'),
            maxMinutes: config()->integer('launchroom.sessions.max_minutes'),
            sessions: $this->recent($instance, $user, InteractiveSessionKind::Console),
        );
    }

    public function data(ServiceInstance $instance, User $user, bool $passwordRequired): ?DataTabData
    {
        $source = $this->targets->database($instance);

        if (! $source instanceof DatabaseSource) {
            return null;
        }

        $placement = DatabaseWorkloads::servingPlacement($instance);
        $running = $placement instanceof Placement && $this->targets->replica($instance, $placement->workload_id, InteractiveSessionKind::Data) instanceof Placement;
        $feature = $source->engine->daemonFeature();
        $outdated = $running && $feature instanceof DaemonFeature && ! $placement->server->supportsDaemonFeature($feature)
            ? "Update launchroomd on {$placement->server->name} to browse {$source->engine->label()}."
            : null;

        return new DataTabData(
            available: $this->relay->available() && $running && $outdated === null,
            unavailableReason: $this->unavailable($running, $source->engine->label().' isn’t running right now. Deploy it to browse its data.')
                ?? $outdated,
            engine: $source->engine->value,
            engineLabel: $source->engine->label(),
            dialect: $source->engine->dataDialect(),
            replica: $running ? SessionReplicaData::fromPlacement($placement) : null,
            passwordRequired: $passwordRequired,
            idleMinutes: config()->integer('launchroom.sessions.idle_minutes'),
            history: $this->history($instance, $user),
        );
    }

    /**
     * @return list<SessionHistoryItemData>
     */
    public function recent(ServiceInstance $instance, User $user, InteractiveSessionKind $kind): array
    {
        $everyone = $user->hasTeamPermission($instance->team_id, Permission::ManageMembers);

        return array_values(InteractiveSession::query()
            ->where('service_instance_id', $instance->id)
            ->where('kind', $kind->value)
            ->unless($everyone, static fn ($query) => $query->where('user_id', $user->id))
            ->with(['user:id,name', 'server:id,name'])
            ->latest('id')
            ->limit(self::RECENT)
            ->get()
            ->map(static fn (InteractiveSession $session): SessionHistoryItemData => self::item($session, $user))
            ->all());
    }

    public static function item(InteractiveSession $session, User $user): SessionHistoryItemData
    {
        $from = $session->started_at ?? $session->created_at;

        return new SessionHistoryItemData(
            id: $session->public_id,
            kind: $session->kind,
            status: $session->status,
            userName: $session->user->name,
            isMine: $session->user_id === $user->id,
            replicaLabel: 'Replica '.($session->slot + 1),
            serverName: $session->server?->name,
            shell: $session->shell,
            openedAt: ($session->created_at ?? now())->toIso8601String(),
            endedAt: $session->ended_at?->toIso8601String(),
            durationSeconds: $session->ended_at !== null && $from !== null ? (int) abs($session->ended_at->diffInSeconds($from)) : null,
            endReason: $session->end_reason?->sentence(),
            exitCode: $session->exit_code,
            canEnd: $session->status->isLive() && Gate::forUser($user)->allows('end', $session),
            hasRecording: ! $session->status->isLive() && $session->record && Gate::forUser($user)->allows('viewRecording', $session),
        );
    }

    /**
     * @return list<QueryHistoryItemData>
     */
    public function history(ServiceInstance $instance, User $user): array
    {
        $instance->loadMissing('environment.project');
        $project = $instance->environment->project;

        return array_values(DatabaseQuery::query()
            ->where('service_instance_id', $instance->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(config()->integer('launchroom.sessions.history_limit'))
            ->get()
            ->map(function (DatabaseQuery $query) use ($project): QueryHistoryItemData {
                try {
                    $statement = $this->keyring->decryptQuery($project, $query->public_id, $query->statement);
                } catch (Throwable) {
                    $statement = null;
                }

                return new QueryHistoryItemData(
                    id: $query->public_id,
                    statement: $statement,
                    status: $query->status,
                    allowWrites: $query->allow_writes,
                    truncated: $query->truncated,
                    rows: $query->rows,
                    durationMs: $query->duration_ms,
                    createdAt: ($query->created_at ?? now())->toIso8601String(),
                );
            })
            ->all());
    }

    private function unavailable(bool $running, string $notRunning): ?string
    {
        return match (true) {
            config('launchroom.sessions.enabled') !== true => 'Live sessions are turned off on this launchroom.',
            ! $this->relay->available() => 'Live sessions need realtime updates (Reverb), which are off on this launchroom.',
            ! $running => $notRunning,
            default => null,
        };
    }
}
