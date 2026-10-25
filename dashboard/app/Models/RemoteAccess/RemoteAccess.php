<?php

declare(strict_types=1);

namespace App\Models\RemoteAccess;

use App\Enums\DatabaseEngine;
use App\Enums\RemoteAccess\RemoteAccessEndReason;
use App\Enums\RemoteAccess\RemoteAccessKind;
use App\Enums\RemoteAccess\RemoteAccessStatus;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\DaemonTask;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One temporary access (D61, D67, docs/03 §12, docs/09 R39). The password is
 * encrypted, shown only to the person who opened it while the access is
 * live and wiped when it ends; launchroomd gets it only sealed to its server.
 *
 * @property int $id
 * @property string $public_id
 * @property int $project_id
 * @property int $environment_id
 * @property int $service_instance_id
 * @property int|null $volume_id the volume a volume access serves (D67)
 * @property int|null $server_id
 * @property int $user_id
 * @property int|null $daemon_task_id
 * @property RemoteAccessKind $kind
 * @property RemoteAccessStatus $status
 * @property string $workload_id
 * @property int $slot
 * @property DatabaseEngine|null $engine
 * @property string $username
 * @property string|null $password
 * @property list<string> $public_keys
 * @property list<string> $allow_from
 * @property list<string> $hosts
 * @property bool $allow_writes
 * @property int $idle_seconds
 * @property int $max_seconds
 * @property Carbon $expires_at
 * @property int|null $port
 * @property array{type: string, sha256: string, publicKey: string}|null $host_key
 * @property array{sha256: string, pem: string}|null $certificate
 * @property string|null $database_name
 * @property array{uid: int, gid: int}|null $volume_owner who SFTP reads and writes as in a volume access
 * @property list<string> $private_addresses
 * @property bool $behind_nat
 * @property int $active_connections
 * @property int $connections
 * @property int $auth_failures
 * @property int $bytes_in
 * @property int $bytes_out
 * @property Carbon|null $listening_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $ended_at
 * @property RemoteAccessEndReason|null $end_reason
 * @property int|null $ended_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Environment $environment
 * @property-read ServiceInstance $serviceInstance
 * @property-read Server|null $server
 * @property-read Volume|null $volume
 * @property-read User $user
 * @property-read DaemonTask|null $daemonTask
 */
#[Fillable(['team_id', 'project_id', 'environment_id', 'service_instance_id', 'volume_id', 'server_id', 'user_id', 'kind', 'workload_id', 'slot', 'engine', 'username', 'password', 'public_keys', 'allow_from', 'hosts', 'allow_writes', 'idle_seconds', 'max_seconds', 'expires_at'])]
#[Hidden(['password'])]
#[Table(name: 'remote_accesses')]
final class RemoteAccess extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'public_keys' => '[]',
        'hosts' => '[]',
        'private_addresses' => '[]',
        'behind_nat' => false,
        'allow_writes' => false,
        'active_connections' => 0,
        'connections' => 0,
        'auth_failures' => 0,
        'bytes_in' => 0,
        'bytes_out' => 0,
        'slot' => 0,
    ];

    public static function publicIdPrefix(): string
    {
        return 'acc';
    }

    /**
     * The only way an access' status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(RemoteAccessStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;
        $this->save();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Volume, $this>
     */
    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DaemonTask, $this>
     */
    public function daemonTask(): BelongsTo
    {
        return $this->belongsTo(DaemonTask::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RemoteAccessKind::class,
            'status' => RemoteAccessStatus::class,
            'engine' => DatabaseEngine::class,
            'end_reason' => RemoteAccessEndReason::class,
            'password' => 'encrypted',
            'public_keys' => 'array',
            'allow_from' => 'array',
            'hosts' => 'array',
            'host_key' => 'array',
            'certificate' => 'array',
            'volume_owner' => 'array',
            'private_addresses' => 'array',
            'allow_writes' => 'boolean',
            'behind_nat' => 'boolean',
            'slot' => 'integer',
            'idle_seconds' => 'integer',
            'max_seconds' => 'integer',
            'port' => 'integer',
            'active_connections' => 'integer',
            'connections' => 'integer',
            'auth_failures' => 'integer',
            'bytes_in' => 'integer',
            'bytes_out' => 'integer',
            'expires_at' => 'datetime',
            'listening_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
