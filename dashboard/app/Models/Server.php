<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Ingress\IngressReportData;
use App\Data\Security\WafReportData;
use App\Data\Servers\ControlPlaneUrlReportData;
use App\Data\Servers\Host\HostStatusData;
use App\Data\Servers\HostFactsData;
use App\Data\Servers\MeshReportData;
use App\Data\Servers\PinnedLocationData;
use App\Data\Servers\RenderErrorData;
use App\Data\Servers\ServerRuntimeData;
use App\Data\Servers\StateRejectionData;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\DaemonFeature;
use App\Enums\DeviceKind;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\ServerStatusChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property MeshReportData|null $mesh_status
 * @property Carbon|null $mesh_status_at
 * @property int $desired_version
 * @property int $observed_version
 * @property int $last_report_seq
 * @property Carbon|null $last_report_at
 * @property HostStatusData|null $host_status latest Report.host
 * @property Carbon|null $host_status_at
 * @property IngressReportData|null $ingress_status
 * @property Carbon|null $ingress_status_at
 * @property WafReportData|null $waf_status
 * @property RenderErrorData|null $render_error
 * @property StateRejectionData|null $state_rejection
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $enrolled_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $revoked_at
 * @property int|null $replaced_by_server_id the server that replaced this dead one (docs/14 §5.3)
 * @property Carbon|null $replaced_at
 * @property DeviceKind|null $device_override the device the user picked ("Looks like"); null = detected
 * @property PinnedLocationData|null $location
 * @property bool $measure_links measure private-network links to its peers (docs/05 §2.1; per-server opt-out)
 * @property list<string>|null $daemon_features optional protocol features its launchroomd understands (Report.host.features, docs/03 §10)
 * @property ControlPlaneUrlReportData|null $daemon_control_plane
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

    /** @use HasStatusTransitions<ServerStatus> */
    use HasStatusTransitions;

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
     * @param  ServerStatus  $from
     * @param  ServerStatus  $to
     */
    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void
    {
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

    /**
     * Memory for scheduling: the latest report first, the enrollment facts
     * otherwise; null when unknown (no capacity check).
     */
    public function memoryMb(): ?int
    {
        return $this->host_status?->reportedMemoryMb() ?? $this->facts->memoryMb;
    }

    public function cpus(): ?int
    {
        return $this->host_status?->reportedCpus() ?? $this->facts->cpus;
    }

    /**
     * @return array{int, CarbonImmutable}|null
     */
    public function availableMemory(): ?array
    {
        return $this->host_status?->availableMemory($this->host_status_at);
    }

    public function hasRole(ServerRole $role): bool
    {
        return $this->roles->contains($role);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->whereIn('status', ServerStatus::approved());
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function reachable(Builder $query): void
    {
        $query->whereIn('status', ServerStatus::reachable());
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function online(Builder $query): void
    {
        $query->where('status', ServerStatus::Online);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withRole(Builder $query, ServerRole $role): void
    {
        $query->whereJsonContains('roles', $role->value);
    }

    /**
     * The edges routes are rendered for: every approved edge, offline ones
     * included, so a server that comes back serves its routes at once.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function edges(Builder $query): void
    {
        $query->approved()->withRole(ServerRole::Edge);
    }

    /**
     * The servers that belong in the mesh: approved and not revoked, across
     * all teams of the instance (one install = one tailnet, docs/05 §2).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function meshMembers(Builder $query): void
    {
        $query->whereNull('revoked_at')->approved();
    }

    /**
     * The edges traffic may be pointed at now (online, or only missing
     * heartbeats): what DNS records and pages suggest. Offline edges keep
     * their routes ({@see self::edges()}) but aren't suggested.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function reachableEdges(Builder $query): void
    {
        $query->reachable()->withRole(ServerRole::Edge);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function onlineBuilders(Builder $query): void
    {
        $query->online()->withRole(ServerRole::Build);
    }

    /**
     * Servers whose trust wasn't removed. RevokeServer sets both the status
     * and `revoked_at`; either one counts as revoked, so rows written by
     * older code or tests that set only one never pass as trusted.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notRevoked(Builder $query): void
    {
        $query->whereIn('status', ServerStatus::nonTerminal())->whereNull('revoked_at');
    }

    /**
     * The control-plane server, the one running the dashboard (docs/14 §5).
     * Rule: flagged `is_control_plane` and not revoked. At most one server
     * holds the flag outside a hand-over transaction: revoking, retiring or
     * recovering away from a server clears it, and {@see self::currentControlPlane()}
     * takes the newest one if a takeover is mid-way.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function controlPlane(Builder $query): void
    {
        $query->where('is_control_plane', true)->notRevoked();
    }

    public static function currentControlPlane(): ?self
    {
        return self::query()->controlPlane()->latest('id')->first();
    }

    /**
     * Servers "Replace server" applies to (docs/14 §5.3): down and not the
     * control plane (that one is recovered with `launchroom:restore`).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function replaceable(Builder $query): void
    {
        $query->where('is_control_plane', false)->whereIn('status', ServerStatus::down());
    }

    public function isReplaceable(): bool
    {
        return ! $this->is_control_plane && $this->status->isDown();
    }

    /**
     * The online server of this server's team that a move or replacement
     * picked, refused on `$field` otherwise.
     */
    public function onlineTeammate(string $publicId, string $field): self
    {
        return self::query()->where('team_id', $this->team_id)->where('public_id', $publicId)->online()->first()
            ?? throw DomainRuleViolation::on($field, 'Pick an online server of this team.');
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
            'host_status' => HostStatusData::class,
            'ingress_status' => IngressReportData::class,
            'waf_status' => WafReportData::class,
            'ingress_status_at' => 'datetime',
            'host_status_at' => 'datetime',
            'mesh_status' => MeshReportData::class,
            'mesh_status_at' => 'datetime',
            'render_error' => RenderErrorData::class,
            'state_rejection' => StateRejectionData::class,
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
            'location' => PinnedLocationData::class,
            'measure_links' => 'boolean',
            'daemon_features' => 'array',
            'daemon_control_plane' => ControlPlaneUrlReportData::class,
            'runtime' => ServerRuntimeData::class,
        ];
    }
}
