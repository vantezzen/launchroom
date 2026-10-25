<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\TaskSealingFailed;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\Server;
use App\Models\Sessions\InteractiveSession;
use App\Models\Team;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Git\GitProviders;
use App\Support\Crypto\TaskSealer;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Git\HostKey;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Sealing at lease time (docs/03 §7): task specs are stored as templates
 * without secrets; `daemon_tasks.seal` only references what a task needs.
 * When a specific server leases the task, the secrets are resolved and
 * sealed to that server's X25519 key, bound to (server, task):
 *
 * - `env`: `{"deployment": id, "keys": [..] | null, "defaults": bool}` → the
 *   deployment's env snapshot (only `keys` when given; plus the build summary
 *   runtime defaults when `defaults`), sealed as TaskEnv into `spec.env`; or
 *   `{"names": [..]}` → those names with empty values (plan previews only
 *   need names); or `{"values": {NAME: value}}` → literal non-secret values
 *   (the verification run of a generated Dockerfile: `PORT`, docs/20 §5);
 * - `cloneToken`: `{"source": GitSource}` → the source's provider credentials
 *   (docs/19 §6): an HTTP token (a fresh repository-scoped GitHub
 *   installation token, the GitLab/Gitea connection token) sealed as
 *   TaskToken purpose `clone` into `spec.cloneToken`, or a deploy key seed
 *   sealed as purpose `ssh_key` into `spec.sshKey` plus the pinned host keys
 *   as `spec.repo.knownHosts`; public remotes get nothing;
 * - `sourceCloneToken`: the same into `spec.source` (inventory tasks, docs/03 §13.1);
 * - `sessionKey`: `{"session": ses_…}` → the interactive session's key,
 *   sealed as TaskToken (purpose session) into `spec.key` (docs/03 §11).
 *
 * Nothing sealed is stored: a retried lease seals again for its server.
 */
final readonly class TaskSecrets
{
    public function __construct(
        private ProjectKeyring $keyring,
        private GitProviders $providers,
    ) {}

    /**
     * The spec to send to $server.
     *
     * @throws TaskSealingFailed
     */
    public function materialize(DaemonTask $task, Server $server): stdClass
    {
        $spec = json_decode(json_encode($task->spec, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);

        if (! $spec instanceof stdClass) {
            throw new TaskSealingFailed('The task has no spec.');
        }

        $seal = $task->seal ?? [];

        try {
            if (is_array($seal['env'] ?? null)) {
                $spec->env = (object) ['sealed' => TaskSealer::env($server->sealing_public_key, $server->public_id, $task->public_id, $this->env($task, $seal['env']))];
            }

            if (is_array($seal['cloneToken'] ?? null)) {
                $this->cloneCredentials($spec, $task, $server, $seal['cloneToken']);
            }

            // Inventory tasks carry the build's repository under `source` (docs/03 §13.1).
            if (is_array($seal['sourceCloneToken'] ?? null) && ($spec->source ?? null) instanceof stdClass) {
                $this->cloneCredentials($spec->source, $task, $server, $seal['sourceCloneToken']);
            }

            if (is_array($seal['accessPassword'] ?? null)) {
                $spec->password = (object) ['sealed' => $this->accessPassword($task, $server, $seal['accessPassword'])];
            }

            if (is_array($seal['sessionKey'] ?? null)) {
                $spec->key = (object) ['sealed' => $this->sessionKey($task, $server, $seal['sessionKey'])];
            }
        } catch (InvalidArgumentException $e) {
            throw new TaskSealingFailed($e->getMessage());
        }

        return $spec;
    }

    /**
     * @param  array<mixed>  $reference
     * @return array<string, string>
     */
    private function env(DaemonTask $task, array $reference): array
    {
        if (is_array($reference['names'] ?? null)) {
            $names = array_filter($reference['names'], is_string(...));

            return array_fill_keys($names, '');
        }

        // Literal, non-secret values (the verification run's PORT, docs/20 §5).
        if (is_array($reference['values'] ?? null)) {
            $values = [];

            foreach ($reference['values'] as $name => $value) {
                if (is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D', $name) === 1 && is_string($value) && strlen($value) <= 1024) {
                    $values[$name] = $value;
                }
            }

            ksort($values);

            return $values;
        }

        $deployment = Deployment::query()
            ->with('environment.project', 'serviceInstance.service', 'revision')
            ->where('team_id', $task->team_id)
            ->whereKey(is_int($reference['deployment'] ?? null) ? $reference['deployment'] : 0)
            ->first();

        if (! $deployment instanceof Deployment || $deployment->env_snapshot === null) {
            throw new TaskSealingFailed('The deployment this task belongs to has no environment snapshot.');
        }

        $env = $this->keyring->decryptSnapshot($deployment->environment->project, $deployment->public_id, $deployment->env_snapshot);

        if (is_array($reference['keys'] ?? null)) {
            $keys = array_filter($reference['keys'], is_string(...));
            $env = array_intersect_key($env, array_flip($keys));
        }

        if (($reference['defaults'] ?? false) === true) {
            $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);

            if ($spec !== null) {
                $env = [...EffectiveDeploy::for($spec, $deployment)->envDefaults, ...$env];
            }
        }

        ksort($env);

        return $env;
    }

    /**
     * The interactive session's key, sealed as TaskToken purpose `session`
     * (docs/03 §11.1). The key is only kept until this lease: the session
     * opens and its stored copy is wiped in the same transaction.
     *
     * @param  array<mixed>  $reference
     */
    private function sessionKey(DaemonTask $task, Server $server, array $reference): string
    {
        $session = InteractiveSession::query()
            ->where('team_id', $task->team_id)
            ->where('daemon_task_id', $task->id)
            ->where('public_id', is_string($reference['session'] ?? null) ? $reference['session'] : '')
            ->lockForUpdate()
            ->first();

        $key = $session?->session_key;

        if (! $session instanceof InteractiveSession || $session->status !== InteractiveSessionStatus::Pending || ! is_string($key) || $key === '') {
            throw new TaskSealingFailed('The session this task belongs to is no longer waiting for its server.');
        }

        $sealed = TaskSealer::token($server->sealing_public_key, $server->public_id, $task->public_id, 'session', $key);
        $session->forceFill(['session_key' => null]);
        $session->transitionTo(InteractiveSessionStatus::Open);

        return $sealed;
    }

    /**
     * The password of a temporary access, sealed as TaskToken purpose
     * `access` to the leasing server and task (docs/03 §12.1). The dashboard
     * keeps its encrypted copy for the opener until the access ends.
     *
     * @param  array<mixed>  $reference
     */
    private function accessPassword(DaemonTask $task, Server $server, array $reference): string
    {
        $access = RemoteAccess::query()
            ->where('team_id', $task->team_id)
            ->where('daemon_task_id', $task->id)
            ->where('public_id', is_string($reference['access'] ?? null) ? $reference['access'] : '')
            ->where('server_id', $server->id)
            ->lockForUpdate()
            ->first();

        $password = $access?->password;

        if (! $access instanceof RemoteAccess || $access->status !== RemoteAccessStatus::Pending || ! is_string($password) || $password === '') {
            throw new TaskSealingFailed('The access this task belongs to is no longer waiting for its server.');
        }

        $sealed = TaskSealer::token($server->sealing_public_key, $server->public_id, $task->public_id, 'access', $password);
        $access->transitionTo(RemoteAccessStatus::Open);

        return $sealed;
    }

    /**
     * @param  array<mixed>  $reference
     */
    private function cloneCredentials(stdClass $spec, DaemonTask $task, Server $server, array $reference): void
    {
        $team = Team::query()->whereKey($task->team_id)->first();
        $source = is_array($reference['source'] ?? null) ? GitSource::from($reference['source']) : null;

        if (! $team instanceof Team || ! $source instanceof GitSource) {
            throw new TaskSealingFailed('The task has no repository to clone.', 'clone_error');
        }

        $provider = $source->kind()->label();

        try {
            $credentials = $this->providers->forSource($source)->cloneCredentials(RepositoryRef::fromSource($team->id, $source));
        } catch (DomainRuleViolation $e) {
            throw new TaskSealingFailed($e->getMessage(), 'clone_error');
        } catch (Throwable) {
            throw new TaskSealingFailed("{$provider} did not issue a clone token for this repository; it will be retried.", 'transient');
        }

        if ($credentials->token !== null) {
            $spec->cloneToken = (object) ['sealed' => TaskSealer::token($server->sealing_public_key, $server->public_id, $task->public_id, 'clone', $credentials->token)];
        }

        if ($credentials->sshKeySeed !== null) {
            $spec->sshKey = (object) ['sealed' => TaskSealer::token($server->sealing_public_key, $server->public_id, $task->public_id, 'ssh_key', $credentials->sshKeySeed)];

            if (! ($spec->repo ?? null) instanceof stdClass) {
                throw new TaskSealingFailed('The task has no repository to clone.', 'clone_error');
            }

            $spec->repo->knownHosts = array_map(static fn (HostKey $key): array => ['type' => $key->type, 'key' => $key->key], $credentials->knownHosts);
        }
    }
}
