<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Servers\HostFactsData;
use App\Data\Servers\ServerRuntimeData;
use App\Enums\DaemonFeature;
use App\Enums\DeviceKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\ServerStatusChanged;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An enrolled server running launchroomd (docs/03, docs/07).
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property Collection<int, ServerRole> $roles
 * @property array<string, string> $labels
 * @property ServerStatus $status
 * @property bool $is_control_plane
 * @property bool $allow_previews placement flag "Allow previews" (docs/06 §5)
 * @property bool $allow_production placement flag "Allow production"
 * @property string|null $daemon_version
 * @property string|null $protocol_version
 * @property string $signing_public_key
 * @property string $sealing_public_key
 * @property HostFactsData $facts
 * @property string|null $public_ipv4
 * @property string|null $public_ipv6
 * @property string|null $mesh_ip
 * @property string|null $mesh_ipv6
 * @property string|null $mesh_node_id Headscale node id (embedded mesh), linked by the pre-auth key it registered with
 * @property list<string> $mesh_auth_key_ids Headscale pre-auth key ids issued to this server (newest last): its node's identity
 * @property Carbon|null $shared_edge_approved_at an instance owner admitted this edge to the install-wide certificate storage and DNS-01 credentials (docs/05 §5)
 * @property int|null $shared_edge_approved_by
 * @property array{state: string, ipv4: string|null, ipv6: string|null, message: string|null, checkedAt: string|null, peers: list<array{ipv4: string, online: bool, connection: string, relay: string|null, lastHandshake: string|null}>}|null $mesh_status latest Report.host.mesh (App\Support\Servers\MeshStatusFacts)
 * @property Carbon|null $mesh_status_at
 * @property int $desired_version
 * @property int $observed_version
 * @property int $last_report_seq
 * @property Carbon|null $last_report_at
 * @property array<string, mixed>|null $host_status latest Report.host (App\Support\Servers\HostStatusFacts)
 * @property Carbon|null $host_status_at
 * @property array{state?: string, routes?: int, storage?: array{state: string, mirrored: int, message?: string}}|null $ingress_status latest Report.ingress summary (App\Actions\Servers\RecordCertificates)
 * @property Carbon|null $ingress_status_at
 * @property array{state: string, since: string, engine?: string, ruleSet?: string, routes: int, droppedRecords?: int, message?: string, reportedAt: string}|null $waf_status latest Report.ingress.waf (docs/03 §13.4) and since when its state holds
 * @property array{message: string, problems: list<string>, deployments: list<string>, at: string}|null $render_error the latest NodeState render failed validation (the previous version stays published)
 * @property array{version: int, error: string, rejectedAt: string}|null $state_rejection the daemon refused a NodeState (Report.stateRejected)
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $enrolled_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $revoked_at
 * @property int|null $replaced_by_server_id the server that replaced this dead one (docs/14 §5.3)
 * @property Carbon|null $replaced_at
 * @property DeviceKind|null $device_override the device the user picked ("Looks like"); null = detected
 * @property array{label: string, latitude: float, longitude: float, countryCode: string|null}|null $location the location the user confirmed or entered
 * @property bool $measure_links measure private-network links to its peers (docs/05 §2.1; per-server opt-out)
 * @property list<string>|null $daemon_features optional protocol features its launchroomd understands (Report.host.features, docs/03 §10)
 * @property array{configured?: string, adopted?: string, active?: string, state: string, message?: string, reportedAt: string}|null $daemon_control_plane which dashboard URL launchroomd uses (Report.host.controlPlane, docs/05 §4.1)
 * @property ServerRuntimeData|null $runtime how Docker runs and what launchroomd can do there (Report.host.runtime, D49); null until reported
 * @property Carbon|null $client_certificate_seen_at last signed request with a verified client certificate of this server (docs/03 §4.3)
 * @property Carbon|null $client_certificate_missing_at last signed request without one while the instance checks certificates
 * @property Carbon|null $client_certificate_reissue_until an instance owner allowed a new certificate while certificates are required
 * @property-read Server|null $replacedBy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'team_id', 'name', 'roles', 'labels', 'is_control_plane', 'daemon_version', 'protocol_version',
    'signing_public_key', 'sealing_public_key', 'facts', 'public_ipv4', 'public_ipv6', 'enrolled_at',
])]
// Public keys aren't secret, but key material is never serialized by default:
// Data objects expose fingerprints instead (docs/09 R12).
#[Hidden(['signing_public_key', 'sealing_public_key'])]
final class Server extends Model
{
    use BelongsToTeam, HasPublicId;

    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending_approval',
        'labels' => '{}',
        'facts' => '{}',
        'allow_previews' => true,
        'allow_production' => true,
        'mesh_auth_key_ids' => '[]',
    ];

    public static function publicIdPrefix(): string
    {
        return 'srv';
    }

    /**
     * Whether this edge gets the install-wide certificate storage and DNS-01
     * credentials (docs/05 §5, docs/09 R14): the control plane, or an edge an
     * instance owner admitted. Team admins can give a server the edge role
     * (it then serves its team's routes with its own certificates) but can't
     * hand it instance secrets.
     */
    public function isSharedEdge(): bool
    {
        return $this->hasRole(ServerRole::Edge) && ($this->is_control_plane || $this->shared_edge_approved_at !== null);
    }

    /**
     * The only way a server's status changes (docs/10 §2.2 rule 3).
     */
    public function transitionTo(ServerStatus $to): void
    {
        $from = $this->status;
        $from->assertCanTransitionTo($to);

        $this->status = $to;
        $this->save();

        event(new ServerStatusChanged($this->id, $from, $to));
    }

    /**
     * Whether this server's launchroomd announced an optional protocol
     * feature (Report.host.features). Decoding is strict, so optional
     * NodeState fields go only to servers that understand them.
     */
    public function supportsDaemonFeature(DaemonFeature $feature): bool
    {
        return in_array($feature->value, $this->daemon_features ?? [], true);
    }

    public function hasRole(ServerRole $role): bool
    {
        return $this->roles->contains($role);
    }

    /**
     * The server that took over from this one ("Replace server", docs/14 §5.3).
     *
     * @return BelongsTo<Server, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_server_id');
    }

    /**
     * @return HasMany<VolumeRetirement, $this>
     */
    public function volumeRetirements(): HasMany
    {
        return $this->hasMany(VolumeRetirement::class);
    }

    /**
     * @return HasMany<NodeStateVersion, $this>
     */
    public function nodeStateVersions(): HasMany
    {
        return $this->hasMany(NodeStateVersion::class);
    }

    /**
     * @return HasOne<NodeStateVersion, $this>
     */
    public function latestNodeState(): HasOne
    {
        return $this->hasOne(NodeStateVersion::class)->ofMany('version', 'max');
    }

    /**
     * @return HasMany<DaemonTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(DaemonTask::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roles' => AsEnumCollection::of(ServerRole::class),
            'labels' => 'array',
            'status' => ServerStatus::class,
            'is_control_plane' => 'boolean',
            'allow_previews' => 'boolean',
            'allow_production' => 'boolean',
            'facts' => HostFactsData::class,
            'desired_version' => 'integer',
            'observed_version' => 'integer',
            'last_report_seq' => 'integer',
            'last_report_at' => 'datetime',
            'host_status' => 'array',
            'ingress_status' => 'array',
            'waf_status' => 'array',
            'ingress_status_at' => 'datetime',
            'host_status_at' => 'datetime',
            'mesh_status' => 'array',
            'mesh_status_at' => 'datetime',
            'render_error' => 'array',
            'state_rejection' => 'array',
            'last_seen_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'replaced_at' => 'datetime',
            'client_certificate_seen_at' => 'datetime',
            'client_certificate_missing_at' => 'datetime',
            'client_certificate_reissue_until' => 'datetime',
            'shared_edge_approved_at' => 'datetime',
            'mesh_auth_key_ids' => 'array',
            'device_override' => DeviceKind::class,
            'location' => 'array',
            'measure_links' => 'boolean',
            'daemon_features' => 'array',
            'daemon_control_plane' => 'array',
            'runtime' => ServerRuntimeData::class,
        ];
    }
}
