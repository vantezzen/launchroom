<?php

declare(strict_types=1);

namespace App\Actions\RemoteAccess;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\RemoteAccess\OpenRemoteAccessData;
use App\Data\Services\DatabaseSource;
use App\Enums\DaemonTaskType;
use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\RemoteAccess\RemoteAccess;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\RemoteAccess\RemoteAccessTargets;
use App\Services\RemoteAccess\RemoteAccessView;
use App\Support\PublicId;
use App\Support\RemoteAccess\AccessSecrets;
use App\Support\RemoteAccess\SshPublicKey;
use Illuminate\Support\Facades\DB;

/**
 * Starts a temporary access (D61, D67, docs/03 §12.1): the row with
 * generated credentials, the `access` task pinned to the replica's server
 * (the password is sealed to that server when it leases the task) and the
 * "started" audit entry. The caller has authorized the user.
 */
final readonly class OpenRemoteAccess
{
    public function __construct(
        private RemoteAccessTargets $targets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $user, ServiceInstance $instance, RemoteAccessKind $kind, OpenRemoteAccessData $data, AuditActor $actor): RemoteAccess
    {
        if (config('launchroom.remote_access.enabled') !== true) {
            throw DomainRuleViolation::conflict('Temporary access is turned off on this launchroom.');
        }

        $instance->loadMissing('service', 'environment.project');
        [$placement, $source, $volume] = $this->target($instance, $kind, $data);
        // The replica list loads only a few server columns.
        $placement->load('server');

        $allow = $data->prefixes();

        if ($allow === []) {
            throw DomainRuleViolation::on('allowFrom', 'Add at least one address that may connect.');
        }

        $keys = $kind->usesSshKeys() ? $data->keys() : [];

        if ($kind === RemoteAccessKind::Database && $data->publicKeys !== []) {
            throw DomainRuleViolation::on('publicKeys', 'Databases use a password.');
        }

        if ($kind === RemoteAccessKind::Ssh && $data->allowWrites) {
            throw DomainRuleViolation::on('allowWrites', 'Only databases and volumes have read-only access.');
        }

        // A database engine's files change under it: its volume opens
        // read-only, data goes through Temporary access or the Data tab (D67).
        if ($kind === RemoteAccessKind::Volume && $data->allowWrites && $instance->service->kind === ServiceKind::Database) {
            throw DomainRuleViolation::on('allowWrites', 'A database’s volume opens read-only. Change data with Temporary access or the Data tab.');
        }

        $writes = $kind !== RemoteAccessKind::Ssh && $data->allowWrites;

        $maxMinutes = min($data->durationMinutes, config()->integer('launchroom.remote_access.max_minutes'));
        $idle = config()->integer('launchroom.remote_access.idle_minutes') * 60;
        $username = $kind->usesSshKeys() ? AccessSecrets::sshUser() : AccessSecrets::databaseRole();
        $password = $keys === [] ? AccessSecrets::password() : null;
        $hosts = RemoteAccessView::certificateHosts($placement->server->public_ipv4, $placement->server->public_ipv6);

        return DB::transaction(function () use ($user, $instance, $kind, $placement, $source, $volume, $allow, $keys, $maxMinutes, $idle, $username, $password, $hosts, $writes, $actor): RemoteAccess {
            // Serialize this user's opens (FOR UPDATE can't be combined with COUNT).
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            $live = [RemoteAccessStatus::Pending->value, RemoteAccessStatus::Open->value];

            if (RemoteAccess::query()->where('user_id', $user->id)->whereIn('status', $live)->count() >= config()->integer('launchroom.remote_access.max_open_per_user')) {
                throw DomainRuleViolation::conflict('You have two temporary accesses open already. End one to start another.');
            }

            if (RemoteAccess::query()->where('server_id', $placement->server_id)->whereIn('status', $live)->count() >= config()->integer('launchroom.remote_access.max_open_per_server')) {
                throw DomainRuleViolation::conflict("{$placement->server->name} has the most temporary accesses it allows open. Try again when one ends.");
            }

            $publicId = PublicId::generate(RemoteAccess::publicIdPrefix());
            $engine = $source?->engine;

            $task = DaemonTask::query()->create([
                'team_id' => $instance->team_id,
                'server_id' => $placement->server_id,
                'pinned_to_server' => true,
                'type' => DaemonTaskType::Access,
                'spec' => (object) array_filter([
                    'access' => $publicId,
                    'kind' => $kind->value,
                    'workload' => $placement->workload_id,
                    'engine' => $engine?->value,
                    'user' => $username,
                    'authorizedKeys' => $keys === [] ? null : array_map(static fn (SshPublicKey $key): string => $key->line(), $keys),
                    'allowFrom' => $allow,
                    'hosts' => $hosts === [] ? null : $hosts,
                    'allowWrites' => $writes ? true : null,
                    'volume' => $volume?->public_id,
                    'image' => $volume instanceof Volume ? config()->string('launchroom.daemon.system_images.files') : null,
                    'idleTimeout' => intdiv($idle, 60).'m',
                ], static fn (mixed $value): bool => $value !== null),
                'max_attempts' => 1,
                'timeout_seconds' => $maxMinutes * 60,
                'purpose' => 'access_'.$kind->value,
                'seal' => $password === null ? null : ['accessPassword' => ['access' => $publicId]],
            ]);

            $access = new RemoteAccess([
                'team_id' => $instance->team_id,
                'project_id' => $instance->environment->project_id,
                'environment_id' => $instance->environment_id,
                'service_instance_id' => $instance->id,
                'volume_id' => $volume?->id,
                'server_id' => $placement->server_id,
                'user_id' => $user->id,
                'kind' => $kind,
                'workload_id' => $placement->workload_id,
                'slot' => $placement->slot,
                'engine' => $engine,
                'username' => $username,
                'password' => $password,
                'public_keys' => array_map(static fn (SshPublicKey $key): string => $key->line(), $keys),
                'allow_from' => $allow,
                'hosts' => $hosts,
                'allow_writes' => $writes,
                'idle_seconds' => $idle,
                'max_seconds' => $maxMinutes * 60,
                'expires_at' => now()->addMinutes($maxMinutes),
            ]);
            $access->public_id = $publicId;
            $access->daemon_task_id = $task->id;
            $access->save();

            $this->audit->handle(new AuditEntryData(
                action: $kind->startedAction(),
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter([
                    'access' => $publicId,
                    'environment' => $instance->environment->public_id,
                    'replica' => $placement->slot + 1,
                    'workload' => $placement->workload_id,
                    'server' => $placement->server->public_id,
                    'engine' => $engine?->value,
                    'volume' => $volume?->public_id,
                    'mountPath' => $volume?->mount_path,
                    'method' => $keys === [] ? 'password' : 'key',
                    'keys' => $keys === [] ? null : array_map(static fn (SshPublicKey $key): string => $key->fingerprint(), $keys),
                    'allowFrom' => $allow,
                    'writes' => $kind === RemoteAccessKind::Ssh ? null : $writes,
                    'minutes' => $maxMinutes,
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $access;
        });
    }

    /**
     * @return array{Placement, DatabaseSource|null, Volume|null}
     */
    private function target(ServiceInstance $instance, RemoteAccessKind $kind, OpenRemoteAccessData $data): array
    {
        if ($kind === RemoteAccessKind::Volume) {
            // Through the instance's own volumes (scoped; removed ones are gone).
            $volume = $data->volume === null ? null : $instance->volumes()->where('public_id', $data->volume)->first();

            if (! $volume instanceof Volume) {
                throw DomainRuleViolation::on('volume', 'Pick one of this service’s volumes.');
            }

            if ($volume->server_id === null) {
                throw DomainRuleViolation::conflict('This volume is created with the next deploy. Deploy the service, then connect.');
            }

            $placement = $this->targets->volumeReplica($instance, $volume);

            if (! $placement instanceof Placement) {
                throw DomainRuleViolation::conflict('The server that holds this volume doesn’t allow SFTP into volumes right now.');
            }

            return [$placement, null, $volume];
        }

        if ($kind === RemoteAccessKind::Database) {
            $source = $instance->service->kind === ServiceKind::Database ? $this->targets->database($instance) : null;

            if (! $source instanceof DatabaseSource) {
                throw DomainRuleViolation::on('service', 'Only databases have temporary database access.');
            }

            if (! RemoteAccessTargets::engineSupported($source->engine)) {
                throw DomainRuleViolation::on('service', $source->engine->label().' has no temporary access yet.');
            }

            $placement = $this->targets->databaseReplica($instance);

            if (! $placement instanceof Placement) {
                throw DomainRuleViolation::conflict($source->engine->label().' isn’t running on a server that allows temporary access.');
            }

            return [$placement, $source, null];
        }

        $replicas = $this->targets->replicas($instance);
        $placement = $data->workload === null
            ? $replicas->first()
            : $replicas->first(static fn (Placement $placement): bool => hash_equals($placement->workload_id, $data->workload));

        if (! $placement instanceof Placement) {
            throw DomainRuleViolation::on('workload', 'No replica of this service is running on a server that allows SSH access.');
        }

        return [$placement, null, null];
    }
}
