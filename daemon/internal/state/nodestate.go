package state

import (
	"fmt"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/cronexpr"
)

// KindNodeState is the kind of a NodeState document.
const KindNodeState = "NodeState"

// NodeState is the signed desired state of one server (docs/03 §5). The daemon
// converges Docker, Caddy and nftables to it and keeps doing so while the
// dashboard is unreachable, so it does not expire.
type NodeState struct {
	TypeMeta
	// ServerID is the server this state is for; the daemon rejects any other.
	ServerID ServerID `json:"serverId"`
	// Version is monotonic per server; the daemon rejects versions <= its current one.
	Version int64 `json:"version" jsonschema:"minimum=1"`
	// IssuedAt is when the dashboard rendered this state (informational).
	IssuedAt time.Time `json:"issuedAt"`
	// System lists launchroom's own sibling containers for this server's roles.
	System SystemSpec `json:"system"`
	// Networks are the per-(project, environment) bridge networks on this server.
	Networks []NetworkSpec `json:"networks"`
	// Volumes are named, launchroom-labelled volumes on this server.
	Volumes []VolumeSpec `json:"volumes"`
	// Workloads are the app containers to run.
	Workloads []WorkloadSpec `json:"workloads"`
	// Ambassadors forward network-local aliases to services on other servers (docs/05 §3).
	Ambassadors []AmbassadorSpec `json:"ambassadors"`
	// Routes is the ingress route table (edge servers only; empty elsewhere).
	Routes []RouteSpec `json:"routes"`
	// Ingress configures certificate storage and DNS-01 for the edge's Caddy
	// (edge servers only; omitted when Caddy's defaults suffice).
	Ingress *IngressSpec `json:"ingress,omitzero" jsonschema:"nullable"`
	// Firewall holds the mesh allow-list for mesh-published ports.
	Firewall FirewallSpec `json:"firewall"`
	// DeleteVolumes explicitly asks launchroomd to delete volumes of deleted
	// environments, services or previews (docs/05 §1.1). Volumes are never
	// deleted just because they left `volumes`. Omitted when empty.
	DeleteVolumes []VolumeDeletion `json:"deleteVolumes,omitzero" jsonschema:"nullable"`
}

// VolumeDeletion is one explicit volume deletion request. launchroomd deletes
// the volume only if it is launchroom-managed for this server, its labels carry
// exactly this volume id, service and environment, it is not declared in this
// NodeState and no container uses it. The outcome is reported in
// Report.volumeDeletions.
type VolumeDeletion struct {
	ID          VolumeID      `json:"id"`
	Name        HostName      `json:"name"`
	Service     ServiceID     `json:"service"`
	Environment EnvironmentID `json:"environment"`
	// RequestedAt is when the dashboard decided to delete it (retention elapsed, final backup taken or confirmed).
	RequestedAt time.Time `json:"requestedAt"`
}

// SystemSpec configures launchroom's system containers. Their container specs
// are built into the daemon; the dashboard only chooses image and enablement.
type SystemSpec struct {
	Caddy     SystemContainer `json:"caddy"`
	Buildkit  SystemContainer `json:"buildkit"`
	Tailscale TailscaleSpec   `json:"tailscale"`
	Registry  RegistrySpec    `json:"registry"`
	// CertStorage (control-plane host only) serves the control plane's
	// Valkey on the mesh for the edges' shared certificate storage (docs/05 §5).
	// Omitted when off.
	CertStorage CertStorageMeshSpec `json:"certStorage,omitzero"`
	// LinkProbe measures the private-network links to the listed peers and
	// answers their probes on <mesh IP>:7400 (docs/05 §2.1). Omitted when off.
	LinkProbe LinkProbeSpec `json:"linkProbe,omitzero"`
	// ControlPlane is the dashboard's canonical public origin (docs/03 §4.2);
	// omitted on the control-plane host and for daemons without the
	// controlPlaneUrl feature.
	ControlPlane ControlPlaneSpec `json:"controlPlane,omitzero"`
}

// CertStorageMeshPort is where the control-plane host serves its Valkey on
// its mesh IP (system.certStorage, docs/05 §5).
const CertStorageMeshPort = 6379

// CertStorageMeshSpec makes launchroomd serve <mesh IP>:6379 → the local Valkey
// (launchroomd's local LR_CERT_STORAGE_UPSTREAM) and allow that port in
// nftables only from AllowFrom (the edges' mesh IPs). Valkey authenticates
// every connection (a dedicated ACL user restricted to the storage's keys).
type CertStorageMeshSpec struct {
	ServeOnMesh bool `json:"serveOnMesh,omitzero"`
	// AllowFrom are the mesh IPs that may connect (requires serveOnMesh).
	AllowFrom []IPAddr `json:"allowFrom,omitzero" jsonschema:"nullable,maxItems=256,uniqueItems=true"`
}

// SystemContainer enables one system container at a digest-pinned image.
type SystemContainer struct {
	Enabled bool `json:"enabled"`
	// Image is required when enabled.
	Image ImageRef `json:"image,omitzero"`
}

// TailscaleSpec configures the mesh client container (docs/05 §2).
type TailscaleSpec struct {
	Enabled bool `json:"enabled"`
	// Image is required when enabled.
	Image ImageRef `json:"image,omitzero"`
	// LoginServer is the coordination server (the embedded Headscale, or the
	// tailnet's for "bring your own Tailscale"); required when enabled. Plain
	// http is refused by launchroomd's local policy unless allowed locally (dev).
	LoginServer MeshLoginURL `json:"loginServer,omitzero"`
	// Tags are ACL tags to advertise (--advertise-tags) such as "tag:lr-edge".
	// Omitted with the embedded Headscale, whose pre-auth keys carry the tags.
	Tags []TailscaleTag `json:"tags,omitzero"`
	// AuthKey is a pre-auth key sealed to this daemon (plaintext: MeshAuthKey,
	// bound to this server). Only present until the server has joined the
	// mesh; launchroomd uses it when the client needs to log in and never
	// writes it anywhere but the client's stdin.
	AuthKey *Sealed `json:"authKey,omitzero" jsonschema:"nullable"`
}

// RegistrySpec configures the 127.0.0.1:5000 registry forwarder (docs/05 §6).
type RegistrySpec struct {
	// ForwardTo is the mesh address of Zot. Empty on the control-plane host, where Zot itself listens.
	ForwardTo AddrPort `json:"forwardTo,omitzero"`
	// ServeOnMesh (control-plane host only) makes launchroomd listen on its
	// mesh IP, port 5000, and forward to the local registry, so other servers
	// reach Zot over the mesh (docs/05 §6).
	ServeOnMesh bool `json:"serveOnMesh,omitzero"`
}

// NetworkSpec is a Docker bridge network with a dashboard-allocated subnet.
type NetworkSpec struct {
	ID     NetworkID `json:"id"`
	Name   HostName  `json:"name"`
	Subnet CIDR      `json:"subnet"`
}

// VolumeSpec is a named Docker volume.
type VolumeSpec struct {
	ID   VolumeID `json:"id"`
	Name HostName `json:"name"`
}

// WorkloadSpec is one app container (one replica of one service in one deployment).
type WorkloadSpec struct {
	ID          WorkloadID    `json:"id"`
	Service     ServiceID     `json:"service"`
	Environment EnvironmentID `json:"environment"`
	Deployment  DeploymentID  `json:"deployment"`
	// Image is digest-pinned and must come from the allowed registry (policy floor).
	Image ImageRef `json:"image"`
	// Command overrides the image ENTRYPOINT as argv. Omitted: image default.
	Command []string `json:"command,omitzero" jsonschema:"nullable,minItems=1,maxItems=256"`
	// Args overrides the image CMD as argv (never a shell string). Omitted: image default.
	Args []string `json:"args,omitzero" jsonschema:"nullable,minItems=1,maxItems=256"`
	// Env is the runtime environment sealed to this daemon's X25519 key. The
	// plaintext is a JSON object {"serverId", "workloadId", "env": {KEY: value}}.
	Env Sealed `json:"env"`
	// Port the app listens on inside the container. Omitted for workers.
	Port *Port `json:"port,omitzero" jsonschema:"nullable"`
	// Health configures the external probe. Omitted: "running without exiting" counts as healthy.
	Health *HealthSpec `json:"health,omitzero" jsonschema:"nullable"`
	// Resources are mandatory limits (policy floor).
	Resources Resources `json:"resources"`
	// Capabilities to add on top of cap-drop ALL; must be in the local allow-list.
	Capabilities []Capability `json:"capabilities,omitzero" jsonschema:"nullable"`
	// Networks to attach, with DNS aliases (service slugs). The first is the primary network.
	Networks []NetworkAttachment `json:"networks" jsonschema:"minItems=1"`
	// Mounts of named volumes. Bind mounts do not exist in the protocol.
	Mounts []Mount `json:"mounts,omitzero" jsonschema:"nullable"`
	// PublishOnMesh publishes Port on the server's mesh IP when consumed from other servers.
	PublishOnMesh *MeshPublish `json:"publishOnMesh,omitzero" jsonschema:"nullable"`
	// Restart is the Docker restart policy.
	Restart RestartPolicy `json:"restart"`
	// Role is serving or draining.
	Role WorkloadRole `json:"role"`
	// DrainUntil is when a draining workload is stopped; required iff role is draining.
	DrainUntil *time.Time `json:"drainUntil,omitzero" jsonschema:"nullable"`
	// ShipLogs makes launchroomd follow the workload's container logs and
	// ship them continuously as workload chunks (runtime log retention,
	// opt-in per environment). It never changes the container.
	ShipLogs bool `json:"shipLogs,omitzero"`
	// WalArchive makes launchroomd ship the WAL segments Postgres archives
	// into a directory of the workload's volume (point-in-time recovery,
	// docs/14 §10). It never changes the container.
	WalArchive *WalArchiveSpec `json:"walArchive,omitzero" jsonschema:"nullable"`
	// Stop is how the container is stopped (daemon feature "stopSpec";
	// database engines, docs/03 §8). Omitted: the image's stop signal
	// through the init process and launchroomd's default grace period.
	Stop *StopSpec `json:"stop,omitzero" jsonschema:"nullable"`
	// Schedule makes the workload a scheduled job (daemon feature
	// "cronSchedule", cron services): launchroomd creates the container
	// without starting it and starts it at every due minute of the cron
	// expression in the time zone, unless the previous run still runs.
	// Requires restart "no" and no port, health or mesh publication.
	Schedule *ScheduleSpec `json:"schedule,omitzero" jsonschema:"nullable"`
}

// CronExpr is a 5-field cron expression (package cronexpr's grammar).
type CronExpr string

var reCronExpr = regexp.MustCompile(`^[0-9*,/-]+( [0-9*,/-]+){4}$`)

// ProtocolSchema implements SchemaDescriber.
func (CronExpr) ProtocolSchema() ScalarSchema { return strSchema(reCronExpr, cronexpr.MaxLength) }

// Validate checks the grammar and every field's range.
func (v CronExpr) Validate() error {
	if err := checkPattern("cron expression", string(v), reCronExpr, cronexpr.MaxLength); err != nil {
		return err
	}
	_, err := cronexpr.Parse(string(v))
	return err
}

// ScheduleSpec is when a scheduled workload runs.
type ScheduleSpec struct {
	// Cron is a 5-field expression (minute hour day month weekday; numbers,
	// "*", ranges, lists and steps only).
	Cron CronExpr `json:"cron"`
	// TimeZone is the IANA zone the expression is read in (e.g. "UTC",
	// "Europe/Berlin").
	TimeZone Timezone `json:"timeZone"`
}

func (s *ScheduleSpec) validate(e *errs, p string) {
	e.check(p+".cron", s.Cron)
	e.check(p+".timeZone", s.TimeZone)
	if s.TimeZone.Validate() == nil {
		if _, err := cronexpr.LoadLocation(string(s.TimeZone)); err != nil {
			e.addf(p+".timeZone", "%v", err)
		}
	}
}

// MaxStopTimeout bounds StopSpec.Timeout (also the policy floor's maximum).
const MaxStopTimeout = 5 * time.Minute

// StopSpec is how a workload's container is stopped (redeploys, removals,
// drains, Docker restarts).
type StopSpec struct {
	// Signal is sent to the container's PID 1 on stop.
	Signal StopSignal `json:"signal"`
	// Timeout is how long the workload may take to exit before it is
	// killed (1s to 5m).
	Timeout Duration `json:"timeout"`
	// NoInit runs no init process: the image's entrypoint must exec the
	// server as PID 1 (it receives Signal from the kernel and reaps its own
	// children). Needed by servers that drop to their own user: the init
	// runs as root without CAP_KILL and cannot forward signals to them.
	NoInit bool `json:"noInit,omitzero"`
}

func (s *StopSpec) validate(e *errs, p string) {
	e.check(p+".signal", s.Signal)
	e.check(p+".timeout", s.Timeout)
	if d := s.Timeout.Std(); d < time.Second || d > MaxStopTimeout {
		e.addf(p+".timeout", "must be between 1s and %s", MaxStopTimeout)
	}
}

// HealthSpec configures an external HTTP or TCP probe against container IP:port.
type HealthSpec struct {
	Type HealthType `json:"type"`
	// Path is required for http probes and forbidden for tcp.
	Path HTTPPath `json:"path,omitzero"`
	// ExpectStatus defaults to "2xx-3xx" for http probes.
	ExpectStatus     StatusRange `json:"expectStatus,omitzero"`
	StartPeriod      Duration    `json:"startPeriod"`
	Interval         Duration    `json:"interval"`
	Timeout          Duration    `json:"timeout"`
	FailureThreshold int         `json:"failureThreshold" jsonschema:"minimum=1,maximum=100"`
}

// Resources are container limits.
type Resources struct {
	CPUs     float64 `json:"cpus" jsonschema:"exclusiveMinimum=0,maximum=1024"`
	MemoryMB int     `json:"memoryMb" jsonschema:"minimum=16,maximum=1048576"`
	Pids     int     `json:"pids" jsonschema:"minimum=16,maximum=1048576"`
}

// NetworkAttachment attaches a workload to a network with DNS aliases.
type NetworkAttachment struct {
	Network NetworkID `json:"network"`
	Aliases []Slug    `json:"aliases"`
}

// Mount mounts a named volume declared in NodeState.volumes.
type Mount struct {
	Volume   VolumeID      `json:"volume"`
	Target   ContainerPath `json:"target"`
	ReadOnly bool          `json:"readOnly,omitzero"`
}

// MeshPublish publishes the workload port on the mesh IP.
type MeshPublish struct {
	Port MeshPort `json:"port"`
}

// AmbassadorSpec is one listener of the lr-ambassador container (docs/05 §3).
type AmbassadorSpec struct {
	Network   NetworkID  `json:"network"`
	Alias     Slug       `json:"alias"`
	Port      Port       `json:"port"`
	Upstreams []AddrPort `json:"upstreams" jsonschema:"minItems=1"`
}

// RouteSpec is an ingress route rendered into Caddy config (docs/05 §4).
type RouteSpec struct {
	ID    RouteID    `json:"id"`
	Hosts []Hostname `json:"hosts" jsonschema:"minItems=1"`
	TLS   TLSMode    `json:"tls"`
	// RedirectWWWTo redirects every other host of the route to this one (www ↔ apex); must be one of Hosts.
	RedirectWWWTo Hostname `json:"redirectWwwTo,omitzero"`
	// Upstreams reference workloads on this server ({"workload", "port"}; the
	// daemon resolves the container IP) or mesh-published replicas on other
	// servers ({"address": "100.64.0.9:31002"}). The daemon only routes to
	// serving workloads that are running and pass their health probe.
	Upstreams []RouteUpstream `json:"upstreams" jsonschema:"minItems=1"`
	// Headers are response headers to set (e.g. X-Robots-Tag on non-production).
	// An empty value removes the header. Values must not contain { or }.
	Headers map[HeaderName]string `json:"headers,omitzero" jsonschema:"nullable"`
	// Protection guards the route (preview protection, docs/06 §5): every request is
	// checked by the dashboard (forward auth) before it is proxied.
	Protection *RouteProtection `json:"protection,omitzero" jsonschema:"nullable"`
	// LoadBalancing selects upstreams. Omitted: round_robin.
	LoadBalancing LoadBalancing `json:"loadBalancing,omitzero"`
	// ClientAuth verifies daemon client certificates (mTLS, D50, docs/03
	// §4.3; daemon feature clientCertificates). Only the dashboard's system
	// routes on the control-plane server carry it.
	ClientAuth *RouteClientAuth `json:"clientAuth,omitzero" jsonschema:"nullable"`
}

// RouteProtection guards a route.
type RouteProtection struct {
	// ForwardAuth asks the dashboard about every request before proxying it.
	ForwardAuth ForwardAuth `json:"forwardAuth"`
}

// ForwardAuth is Caddy forward auth to the dashboard. A 2xx answer lets the
// request through; any other answer (e.g. a 302 to the login, with Set-Cookie)
// is returned to the client as is.
type ForwardAuth struct {
	// URL of the dashboard's preview auth endpoint: http(s)://host[:port]/auth/preview.
	URL ForwardAuthURL `json:"url"`
	// StripHeaders are request headers removed after a successful check, before
	// the request reaches the app (e.g. the CI bypass header X-Launchroom-Bypass).
	StripHeaders []HeaderName `json:"stripHeaders,omitzero" jsonschema:"maxItems=8,uniqueItems=true"`
}

func (p RouteProtection) validate(e *errs, path string) {
	fa := p.ForwardAuth
	e.check(path+".forwardAuth.url", fa.URL)
	checkCount(e, path+".forwardAuth.stripHeaders", len(fa.StripHeaders), 0, 8)
	for i, h := range fa.StripHeaders {
		e.check(idx(path+".forwardAuth.stripHeaders", i), h)
	}
	unique(e, path+".forwardAuth.stripHeaders", fa.StripHeaders)
}

// RouteUpstream is one upstream of a route. Exactly one form is used:
// {"workload": "wl_…", "port": 3000} for a workload in this NodeState, or
// {"address": "ip:port"} for a mesh-published replica on another server.
type RouteUpstream struct {
	// Workload is a workload id from this NodeState (same-server upstream).
	Workload WorkloadID `json:"workload,omitzero"`
	// Port is the container port to proxy to; required with workload.
	Port Port `json:"port,omitzero"`
	// Address is a mesh ip:port (cross-server upstream).
	Address AddrPort `json:"address,omitzero"`
}

func (u RouteUpstream) validate(e *errs, p string, workloads map[WorkloadID]bool) {
	switch {
	case u.Workload != "" && u.Address != "":
		e.addf(p, "use either workload+port or address, not both")
	case u.Workload != "":
		e.check(p+".workload", u.Workload)
		if workloads != nil && !workloads[u.Workload] {
			e.addf(p+".workload", "references unknown workload %s", u.Workload)
		}
		e.check(p+".port", u.Port)
	case u.Address != "":
		e.check(p+".address", u.Address)
		if u.Port != 0 {
			e.addf(p+".port", "is only allowed with workload")
		}
	default:
		e.addf(p, "needs workload+port or address")
	}
}

// FirewallSpec holds nftables allow-lists.
type FirewallSpec struct {
	MeshAllow []MeshAllowRule `json:"meshAllow"`
}

// MeshAllowRule allows a mesh-published port from specific peer mesh IPs.
type MeshAllowRule struct {
	Port MeshPort `json:"port"`
	From []IPAddr `json:"from" jsonschema:"minItems=1"`
}

// Validate checks grammar, ranges and cross-references. It does not apply the
// local policy floor (see internal/policy), which depends on local config.
func (s *NodeState) Validate() error {
	var e errs
	s.check(&e, KindNodeState)
	e.check("serverId", s.ServerID)
	if s.Version < 1 {
		e.addf("version", "must be >= 1")
	}
	if s.IssuedAt.IsZero() {
		e.addf("issuedAt", "is required")
	}
	s.System.validate(&e)
	s.System.LinkProbe.validate(&e, s.ServerID)

	nets := make(map[NetworkID]bool, len(s.Networks))
	checkCount(&e, "networks", len(s.Networks), 0, maxListItems)
	netNames := make([]HostName, 0, len(s.Networks))
	subnets := make([]CIDR, 0, len(s.Networks))
	for i, n := range s.Networks {
		p := idx("networks", i)
		e.check(p+".id", n.ID)
		e.check(p+".name", n.Name)
		e.check(p+".subnet", n.Subnet)
		if nets[n.ID] {
			e.addf(p+".id", "duplicate network id %s", n.ID)
		}
		nets[n.ID] = true
		netNames = append(netNames, n.Name)
		subnets = append(subnets, n.Subnet)
	}
	unique(&e, "networks.name", netNames)
	unique(&e, "networks.subnet", subnets)

	vols := make(map[VolumeID]bool, len(s.Volumes))
	checkCount(&e, "volumes", len(s.Volumes), 0, maxListItems)
	volNames := make([]HostName, 0, len(s.Volumes))
	for i, v := range s.Volumes {
		p := idx("volumes", i)
		e.check(p+".id", v.ID)
		e.check(p+".name", v.Name)
		if vols[v.ID] {
			e.addf(p+".id", "duplicate volume id %s", v.ID)
		}
		vols[v.ID] = true
		volNames = append(volNames, v.Name)
	}
	unique(&e, "volumes.name", volNames)

	checkCount(&e, "workloads", len(s.Workloads), 0, maxListItems)
	wlIDs := make([]WorkloadID, 0, len(s.Workloads))
	wlSet := make(map[WorkloadID]bool, len(s.Workloads))
	meshPorts := make([]MeshPort, 0)
	for i := range s.Workloads {
		w := &s.Workloads[i]
		p := idx("workloads", i)
		w.validate(&e, p, nets, vols)
		wlIDs = append(wlIDs, w.ID)
		wlSet[w.ID] = true
		if w.PublishOnMesh != nil {
			meshPorts = append(meshPorts, w.PublishOnMesh.Port)
		}
	}
	unique(&e, "workloads.id", wlIDs)
	unique(&e, "workloads.publishOnMesh.port", meshPorts)

	checkCount(&e, "ambassadors", len(s.Ambassadors), 0, maxListItems)
	type listener struct {
		net   NetworkID
		alias Slug
		port  Port
	}
	listeners := make([]listener, 0, len(s.Ambassadors))
	for i, a := range s.Ambassadors {
		p := idx("ambassadors", i)
		e.check(p+".network", a.Network)
		if !nets[a.Network] {
			e.addf(p+".network", "references unknown network %s", a.Network)
		}
		e.check(p+".alias", a.Alias)
		e.check(p+".port", a.Port)
		checkCount(&e, p+".upstreams", len(a.Upstreams), 1, 64)
		for j, u := range a.Upstreams {
			e.check(idx(p+".upstreams", j), u)
		}
		listeners = append(listeners, listener{a.Network, a.Alias, a.Port})
	}
	unique(&e, "ambassadors", listeners)

	checkCount(&e, "routes", len(s.Routes), 0, maxListItems)
	routeIDs := make([]RouteID, 0, len(s.Routes))
	var allHosts []Hostname
	for i, r := range s.Routes {
		p := idx("routes", i)
		r.validate(&e, p, wlSet)
		routeIDs = append(routeIDs, r.ID)
		allHosts = append(allHosts, r.Hosts...)
	}
	unique(&e, "routes.id", routeIDs)
	unique(&e, "routes.hosts", allHosts)
	if s.Ingress != nil {
		s.Ingress.validate(&e)
	}

	checkCount(&e, "firewall.meshAllow", len(s.Firewall.MeshAllow), 0, maxListItems)
	fwPorts := make([]MeshPort, 0, len(s.Firewall.MeshAllow))
	for i, r := range s.Firewall.MeshAllow {
		p := idx("firewall.meshAllow", i)
		e.check(p+".port", r.Port)
		checkCount(&e, p+".from", len(r.From), 1, 256)
		for j, ip := range r.From {
			e.check(idx(p+".from", j), ip)
		}
		fwPorts = append(fwPorts, r.Port)
	}
	unique(&e, "firewall.meshAllow.port", fwPorts)

	checkCount(&e, "deleteVolumes", len(s.DeleteVolumes), 0, maxListItems)
	declaredNames := make(map[HostName]bool, len(volNames))
	for _, n := range volNames {
		declaredNames[n] = true
	}
	delIDs := make([]VolumeID, 0, len(s.DeleteVolumes))
	delNames := make([]HostName, 0, len(s.DeleteVolumes))
	for i, d := range s.DeleteVolumes {
		p := idx("deleteVolumes", i)
		e.check(p+".id", d.ID)
		e.check(p+".name", d.Name)
		e.check(p+".service", d.Service)
		e.check(p+".environment", d.Environment)
		if d.RequestedAt.IsZero() {
			e.addf(p+".requestedAt", "is required")
		}
		if vols[d.ID] || declaredNames[d.Name] {
			e.addf(p, "volume %s is still declared in volumes", d.ID)
		}
		delIDs = append(delIDs, d.ID)
		delNames = append(delNames, d.Name)
	}
	unique(&e, "deleteVolumes.id", delIDs)
	unique(&e, "deleteVolumes.name", delNames)

	return e.err()
}

func (s SystemSpec) validate(e *errs) {
	for name, c := range map[string]SystemContainer{"caddy": s.Caddy, "buildkit": s.Buildkit} {
		p := "system." + name
		if c.Enabled || c.Image != "" {
			e.check(p+".image", c.Image)
		}
	}
	t := s.Tailscale
	if t.Enabled || t.Image != "" {
		e.check("system.tailscale.image", t.Image)
	}
	if t.Enabled || t.LoginServer != "" {
		e.check("system.tailscale.loginServer", t.LoginServer)
	}
	checkCount(e, "system.tailscale.tags", len(t.Tags), 0, 16)
	for i, tag := range t.Tags {
		e.check(idx("system.tailscale.tags", i), tag)
	}
	unique(e, "system.tailscale.tags", t.Tags)
	if t.AuthKey != nil {
		e.check("system.tailscale.authKey", *t.AuthKey)
		if !t.Enabled {
			e.addf("system.tailscale.authKey", "is only allowed when enabled")
		}
	}
	cs := s.CertStorage
	checkCount(e, "system.certStorage.allowFrom", len(cs.AllowFrom), 0, 256)
	for i, ip := range cs.AllowFrom {
		e.check(idx("system.certStorage.allowFrom", i), ip)
	}
	unique(e, "system.certStorage.allowFrom", cs.AllowFrom)
	if len(cs.AllowFrom) > 0 && !cs.ServeOnMesh {
		e.addf("system.certStorage.allowFrom", "is only allowed with serveOnMesh")
	}
	if s.ControlPlane.URL != "" {
		e.check("system.controlPlane.url", s.ControlPlane.URL)
	}
	if s.Registry.ForwardTo != "" {
		e.check("system.registry.forwardTo", s.Registry.ForwardTo)
		if s.Registry.ServeOnMesh {
			e.addf("system.registry.serveOnMesh", "is only allowed on the server where the registry runs (no forwardTo)")
		}
	}
}

func (w *WorkloadSpec) validate(e *errs, p string, nets map[NetworkID]bool, vols map[VolumeID]bool) {
	e.check(p+".id", w.ID)
	e.check(p+".service", w.Service)
	e.check(p+".environment", w.Environment)
	e.check(p+".deployment", w.Deployment)
	e.check(p+".image", w.Image)
	checkArgv(e, p+".command", w.Command)
	checkArgv(e, p+".args", w.Args)
	e.check(p+".env", w.Env)
	if w.Port != nil {
		e.check(p+".port", *w.Port)
	}
	if w.Health != nil {
		if w.Port == nil {
			e.addf(p+".health", "requires port")
		}
		w.Health.validate(e, p+".health")
	}
	w.Resources.validate(e, p+".resources")

	checkCount(e, p+".capabilities", len(w.Capabilities), 0, 16)
	for i, c := range w.Capabilities {
		e.check(idx(p+".capabilities", i), c)
	}
	unique(e, p+".capabilities", w.Capabilities)

	checkCount(e, p+".networks", len(w.Networks), 1, 16)
	attached := make([]NetworkID, 0, len(w.Networks))
	for i, n := range w.Networks {
		np := idx(p+".networks", i)
		e.check(np+".network", n.Network)
		if !nets[n.Network] {
			e.addf(np+".network", "references unknown network %s", n.Network)
		}
		checkCount(e, np+".aliases", len(n.Aliases), 0, 16)
		for j, a := range n.Aliases {
			e.check(idx(np+".aliases", j), a)
		}
		attached = append(attached, n.Network)
	}
	unique(e, p+".networks.network", attached)

	checkCount(e, p+".mounts", len(w.Mounts), 0, 32)
	targets := make([]ContainerPath, 0, len(w.Mounts))
	for i, m := range w.Mounts {
		mp := idx(p+".mounts", i)
		e.check(mp+".volume", m.Volume)
		if !vols[m.Volume] {
			e.addf(mp+".volume", "references unknown volume %s", m.Volume)
		}
		e.check(mp+".target", m.Target)
		targets = append(targets, m.Target)
	}
	unique(e, p+".mounts.target", targets)

	if w.PublishOnMesh != nil {
		e.check(p+".publishOnMesh.port", w.PublishOnMesh.Port)
		if w.Port == nil {
			e.addf(p+".publishOnMesh", "requires port")
		}
	}
	e.check(p+".restart", w.Restart)
	e.check(p+".role", w.Role)
	switch {
	case w.Role == RoleDraining && w.DrainUntil == nil:
		e.addf(p+".drainUntil", "is required when role is draining")
	case w.Role != RoleDraining && w.DrainUntil != nil:
		e.addf(p+".drainUntil", "is only allowed when role is draining")
	}
	if w.WalArchive != nil {
		w.WalArchive.validate(e, p+".walArchive", w.Mounts)
	}
	if w.Stop != nil {
		w.Stop.validate(e, p+".stop")
	}
	if w.Schedule != nil {
		w.Schedule.validate(e, p+".schedule")
		switch {
		case w.Restart != RestartNo:
			e.addf(p+".schedule", "requires restart \"no\"")
		case w.Port != nil || w.Health != nil || w.PublishOnMesh != nil:
			e.addf(p+".schedule", "is only allowed without port, health and publishOnMesh")
		}
	}
}

func (h *HealthSpec) validate(e *errs, p string) {
	e.check(p+".type", h.Type)
	switch h.Type {
	case HealthHTTP:
		e.check(p+".path", h.Path)
		if h.ExpectStatus != "" {
			e.check(p+".expectStatus", h.ExpectStatus)
		}
	case HealthTCP:
		if h.Path != "" || h.ExpectStatus != "" {
			e.addf(p, "path and expectStatus are only valid for http probes")
		}
	}
	for name, d := range map[string]Duration{"startPeriod": h.StartPeriod, "interval": h.Interval, "timeout": h.Timeout} {
		e.check(p+"."+name, d)
		if d.Std() > time.Hour {
			e.addf(p+"."+name, "must be at most 1h")
		}
	}
	if h.Timeout.Std() > 0 && h.Interval.Std() > 0 && h.Timeout.Std() > h.Interval.Std() {
		e.addf(p+".timeout", "must not exceed interval")
	}
	if h.FailureThreshold < 1 || h.FailureThreshold > 100 {
		e.addf(p+".failureThreshold", "must be between 1 and 100")
	}
}

func (r Resources) validate(e *errs, p string) {
	if !(r.CPUs > 0 && r.CPUs <= 1024) { // also rejects NaN
		e.addf(p+".cpus", "must be > 0 and <= 1024")
	}
	if r.MemoryMB < 16 || r.MemoryMB > 1<<20 {
		e.addf(p+".memoryMb", "must be between 16 and 1048576")
	}
	if r.Pids < 16 || r.Pids > 1<<20 {
		e.addf(p+".pids", "must be between 16 and 1048576")
	}
}

func (r RouteSpec) validate(e *errs, p string, workloads map[WorkloadID]bool) {
	e.check(p+".id", r.ID)
	checkCount(e, p+".hosts", len(r.Hosts), 1, 100)
	for i, h := range r.Hosts {
		e.check(idx(p+".hosts", i), h)
	}
	e.check(p+".tls", r.TLS)
	if r.RedirectWWWTo != "" {
		e.check(p+".redirectWwwTo", r.RedirectWWWTo)
		if !slices.Contains(r.Hosts, r.RedirectWWWTo) {
			e.addf(p+".redirectWwwTo", "must be one of the route's hosts")
		}
	}
	checkCount(e, p+".upstreams", len(r.Upstreams), 1, 64)
	for i, u := range r.Upstreams {
		u.validate(e, idx(p+".upstreams", i), workloads)
	}
	unique(e, p+".upstreams", r.Upstreams)
	if len(r.Headers) > 32 {
		e.addf(p+".headers", "at most 32 headers")
	}
	for name, v := range r.Headers {
		hp := fmt.Sprintf("%s.headers[%q]", p, truncate(string(name)))
		e.check(hp, name)
		checkText(e, hp, v, maxHeaderValue)
		for _, c := range v {
			if c < 0x20 && c != '\t' || c == 0x7f {
				e.addf(hp, "contains control characters")
				break
			}
		}
		if strings.ContainsAny(v, "{}") {
			// Caddy would expand {placeholders} such as {env.*} in header values.
			e.addf(hp, "must not contain { or }")
		}
	}
	if r.Protection != nil {
		r.Protection.validate(e, p+".protection")
	}
	if r.ClientAuth != nil {
		r.ClientAuth.validate(e, p+".clientAuth")
	}
	if r.LoadBalancing != "" {
		e.check(p+".loadBalancing", r.LoadBalancing)
	}
}
