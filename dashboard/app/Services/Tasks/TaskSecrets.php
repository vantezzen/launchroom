<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Data\Git\RepositoryRef;
use App\Data\Protocol\V1\Sealed;
use App\Data\Protocol\V1\Tasks\AccessSpec;
use App\Data\Protocol\V1\Tasks\BackupDestination as DestinationSpec;
use App\Data\Protocol\V1\Tasks\BackupMaintenanceSpec;
use App\Data\Protocol\V1\Tasks\BackupSnapshotsSpec;
use App\Data\Protocol\V1\Tasks\BackupSpec;
use App\Data\Protocol\V1\Tasks\BuildInputs;
use App\Data\Protocol\V1\Tasks\BuildSpec;
use App\Data\Protocol\V1\Tasks\DbExportSpec;
use App\Data\Protocol\V1\Tasks\HostKey as HostKeySpec;
use App\Data\Protocol\V1\Tasks\InventorySource;
use App\Data\Protocol\V1\Tasks\InventorySpec;
use App\Data\Protocol\V1\Tasks\PlanSpec;
use App\Data\Protocol\V1\Tasks\RepoSpec;
use App\Data\Protocol\V1\Tasks\RestoreRuntime;
use App\Data\Protocol\V1\Tasks\RestoreSpec;
use App\Data\Protocol\V1\Tasks\RunOnceSpec;
use App\Data\Protocol\V1\Tasks\SessionSpec;
use App\Data\Protocol\V1\Tasks\TaskSpec;
use App\Data\Protocol\V1\Tasks\VerifyRestoreSpec;
use App\Data\Protocol\V1\Tasks\VolumeExportSpec;
use App\Data\Tasks\CloneSeal;
use App\Data\Tasks\EnvSeal;
use App\Data\Tasks\TaskSeal;
use App\Enums\TaskBackedAccessStatus;
use App\Enums\TaskFailureClass;
use App\Exceptions\DecryptionFailed;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\TaskSealingFailed;
use App\Models\Backups\BackupDestination;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Sessions\InteractiveSession;
use App\Models\Team;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Git\GitProviders;
use App\Support\Crypto\TaskSealer;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Git\HostKey;
use App\Support\Variables\VariableKey;
use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * Sealing at lease time (docs/03 §7): specs are stored as templates without
 * secrets and `daemon_tasks.seal` ({@see TaskSeal}) only
 * references what a task needs. When a server leases the task, the secrets
 * are resolved and sealed to that server's X25519 key, bound to (server,
 * task). Nothing sealed is stored: a retried lease seals again for its
 * server.
 */
final readonly class TaskSecrets
{
    public function __construct(
        private ProjectKeyring $keyring,
        private GitProviders $providers,
        private DestinationSecrets $destinations,
        private PointInTimeRecovery $pointInTime,
    ) {}

    /**
     * The typed spec to send to $server, its secrets sealed.
     *
     * @throws TaskSealingFailed
     */
    public function materialize(DaemonTask $task, Server $server): TaskSpec
    {
        try {
            $spec = TaskSpec::fromStored($task->type, $task->spec);
        } catch (InvalidArgumentException $e) {
            throw new TaskSealingFailed($e->getMessage());
        }

        $seal = $task->seal;

        if ($seal === null) {
            return $spec;
        }

        try {
            if ($seal->env instanceof EnvSeal) {
                $sealed = $this->sealEnv($server, $task, $this->env($task, $seal->env));

                match (true) {
                    $spec instanceof BuildSpec, $spec instanceof PlanSpec => $spec->inputs->env = $sealed,
                    $spec instanceof RunOnceSpec => $spec->env = $sealed,
                    default => throw new TaskSealingFailed('This task takes no environment.'),
                };
            }

            if ($seal->cloneToken instanceof CloneSeal) {
                $inputs = $spec instanceof BuildSpec || $spec instanceof PlanSpec ? $spec->inputs : null;

                if (! $inputs instanceof BuildInputs) {
                    throw new TaskSealingFailed('The task has no repository to clone.', TaskFailureClass::CloneError);
                }

                [$inputs->cloneToken, $inputs->sshKey] = $this->cloneCredentials($inputs->repo, $task, $server, $seal->cloneToken);
            }

            if ($seal->sourceCloneToken instanceof CloneSeal && $spec instanceof InventorySpec && $spec->source instanceof InventorySource) {
                [$spec->source->cloneToken, $spec->source->sshKey] = $this->cloneCredentials($spec->source->repo, $task, $server, $seal->sourceCloneToken);
            }

            if ($seal->accessPassword !== null && $spec instanceof AccessSpec) {
                $spec->password = $this->sealToken($server, $task, 'access', $this->accessPassword($task, $server, $seal->accessPassword->access));
            }

            if ($seal->sessionKey !== null && $spec instanceof SessionSpec) {
                $spec->key = $this->sealToken($server, $task, 'session', $this->sessionKey($task, $seal->sessionKey->session));
            }

            if ($seal->destination !== null) {
                $destination = $this->destinationOf($spec);

                if (! $destination instanceof DestinationSpec) {
                    throw new TaskSealingFailed('This task reads no backup destination.');
                }

                $destination->credentials = $this->sealEnv($server, $task, $this->destinationCredentials($task, $seal->destination));
            }

            if ($seal->restoreRuntimeEnv !== null) {
                if (! $spec instanceof RestoreSpec || ! $spec->runtime instanceof RestoreRuntime) {
                    throw new TaskSealingFailed('This task runs no database to restore into.');
                }

                $spec->runtime->env = $this->sealEnv($server, $task, $this->restoreRuntimeEnv($task, $seal->restoreRuntimeEnv));
            }
        } catch (InvalidArgumentException $e) {
            throw new TaskSealingFailed($e->getMessage());
        }

        return $spec;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function sealEnv(Server $server, DaemonTask $task, #[SensitiveParameter] array $env): Sealed
    {
        return new Sealed(TaskSealer::env($server->sealing_public_key, $server->public_id, $task->public_id, $env));
    }

    private function sealToken(Server $server, DaemonTask $task, string $purpose, #[SensitiveParameter] string $token): Sealed
    {
        return new Sealed(TaskSealer::token($server->sealing_public_key, $server->public_id, $task->public_id, $purpose, $token));
    }

    /**
     * @return array<string, string>
     */
    private function env(DaemonTask $task, EnvSeal $reference): array
    {
        if ($reference->names !== null) {
            return array_fill_keys($reference->names, '');
        }

        if ($reference->values !== null) {
            $values = [];

            foreach ($reference->values as $name => $value) {
                if (VariableKey::isValid($name) && strlen($value) <= 1024) {
                    $values[$name] = $value;
                }
            }

            ksort($values);

            return $values;
        }

        $deployment = Deployment::query()
            ->with('environment.project', 'serviceInstance.service', 'revision')
            ->where('team_id', $task->team_id)
            ->whereKey($reference->deployment ?? 0)
            ->first();

        if (! $deployment instanceof Deployment || $deployment->env_snapshot === null) {
            throw new TaskSealingFailed('The deployment this task belongs to has no environment snapshot.');
        }

        $env = $this->keyring->decryptSnapshot($deployment->environment->project, $deployment->public_id, $deployment->env_snapshot);

        if ($reference->keys !== null) {
            $env = array_intersect_key($env, array_flip($reference->keys));
        }

        if ($reference->defaults) {
            $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);

            if ($spec !== null) {
                $env = [...EffectiveDeploy::for($spec, $deployment)->envDefaults, ...$env];
            }
        }

        ksort($env);

        return $env;
    }

    /**
     * The interactive session's key (docs/03 §11.1). The key is only kept
     * until this lease: the session opens and wipes it in the same
     * transaction (App\Listeners\Sessions\OpenSessionOnLease).
     */
    private function sessionKey(DaemonTask $task, string $sessionId): string
    {
        $session = InteractiveSession::query()
            ->where('team_id', $task->team_id)
            ->where('daemon_task_id', $task->id)
            ->where('public_id', $sessionId)
            ->lockForUpdate()
            ->first();

        $key = $session?->session_key;

        if (! $session instanceof InteractiveSession || $session->status !== TaskBackedAccessStatus::Pending || ! is_string($key) || $key === '') {
            throw new TaskSealingFailed('The session this task belongs to is no longer waiting for its server.');
        }

        return $key;
    }

    /**
     * The password of a temporary access (docs/03 §12.1). The dashboard keeps
     * its encrypted copy for the opener until the access ends.
     */
    private function accessPassword(DaemonTask $task, Server $server, string $accessId): string
    {
        $access = RemoteAccess::query()
            ->where('team_id', $task->team_id)
            ->where('daemon_task_id', $task->id)
            ->where('public_id', $accessId)
            ->where('server_id', $server->id)
            ->lockForUpdate()
            ->first();

        $password = $access?->password;

        if (! $access instanceof RemoteAccess || $access->status !== TaskBackedAccessStatus::Pending || ! is_string($password) || $password === '') {
            throw new TaskSealingFailed('The access this task belongs to is no longer waiting for its server.');
        }

        return $password;
    }

    /**
     * The source's provider credential (docs/19 §6): an HTTP token (a fresh
     * repository-scoped GitHub installation token, the GitLab/Gitea
     * connection token) as TaskToken purpose `clone`, or a deploy key seed as
     * purpose `ssh_key` plus the pinned host keys in `repo.knownHosts`.
     * Public remotes get nothing.
     *
     * @return array{Sealed|null, Sealed|null}
     */
    private function cloneCredentials(RepoSpec $repo, DaemonTask $task, Server $server, CloneSeal $reference): array
    {
        $team = Team::query()->whereKey($task->team_id)->first();

        if (! $team instanceof Team) {
            throw new TaskSealingFailed('The task has no repository to clone.', TaskFailureClass::CloneError);
        }

        $source = $reference->source;
        $provider = $source->provider->label();

        try {
            $credentials = $this->providers->forSource($source)->cloneCredentials(RepositoryRef::fromSource($team->id, $source));
        } catch (DomainRuleViolation $e) {
            throw new TaskSealingFailed($e->getMessage(), TaskFailureClass::CloneError);
        } catch (Throwable) {
            throw new TaskSealingFailed("{$provider} did not issue a clone token for this repository; it will be retried.", TaskFailureClass::Transient);
        }

        $sshKey = null;

        if ($credentials->sshKeySeed !== null) {
            $sshKey = $this->sealToken($server, $task, 'ssh_key', $credentials->sshKeySeed);
            $repo->knownHosts = array_map(static fn (HostKey $key): HostKeySpec => new HostKeySpec($key->type, $key->key), $credentials->knownHosts);
        }

        return [$credentials->token === null ? null : $this->sealToken($server, $task, 'clone', $credentials->token), $sshKey];
    }

    /**
     * A backup destination's credentials as the TaskEnv restic reads
     * (docs/14 §7): AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, RESTIC_PASSWORD.
     *
     * @return array<string, string>
     */
    private function destinationCredentials(DaemonTask $task, int $destinationId): array
    {
        $destination = BackupDestination::query()
            ->whereKey($destinationId)
            ->where(static fn (Builder $query) => $query->whereNull('team_id')->orWhere('team_id', $task->team_id))
            ->first();

        if (! $destination instanceof BackupDestination) {
            throw new TaskSealingFailed('The backup destination of this task no longer exists.');
        }

        try {
            $credentials = $this->destinations->reveal($destination);
        } catch (DecryptionFailed) {
            throw new TaskSealingFailed('The credentials of the backup destination could not be read.');
        }

        return [
            'AWS_ACCESS_KEY_ID' => $credentials->accessKeyId,
            'AWS_SECRET_ACCESS_KEY' => $credentials->secretAccessKey,
            'RESTIC_PASSWORD' => $credentials->repositoryPassword,
        ];
    }

    /**
     * POSTGRES_USER, POSTGRES_PASSWORD and POSTGRES_DB of the database a
     * point-in-time restore replays into (docs/14 §10.3).
     *
     * @return array<string, string>
     */
    private function restoreRuntimeEnv(DaemonTask $task, int $instanceId): array
    {
        $instance = ServiceInstance::query()->where('team_id', $task->team_id)->whereKey($instanceId)->first();
        $env = $instance instanceof ServiceInstance ? $this->pointInTime->postgresEnv($instance) : null;

        if ($env === null) {
            throw new TaskSealingFailed('The database to restore into has no credentials to replay with.');
        }

        return $env;
    }

    private function destinationOf(TaskSpec $spec): ?DestinationSpec
    {
        return match (true) {
            $spec instanceof BackupSpec, $spec instanceof RestoreSpec, $spec instanceof VerifyRestoreSpec,
            $spec instanceof BackupSnapshotsSpec, $spec instanceof BackupMaintenanceSpec => $spec->destination,
            $spec instanceof DbExportSpec, $spec instanceof VolumeExportSpec => $spec->destination,
            default => null,
        };
    }
}
