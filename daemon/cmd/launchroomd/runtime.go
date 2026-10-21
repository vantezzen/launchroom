package main

import (
	"context"
	"crypto/ecdh"
	"encoding/json"
	"log/slog"
	"net"
	"net/netip"
	"net/url"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	bkclient "github.com/moby/buildkit/client"

	"github.com/vantezzen/launchroom/daemon/internal/ambassadord"
	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/cleanup"
	"github.com/vantezzen/launchroom/daemon/internal/clientcert"
	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/linkprobe"
	"github.com/vantezzen/launchroom/daemon/internal/logship"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/metrics"
	"github.com/vantezzen/launchroom/daemon/internal/nudge"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
	"github.com/vantezzen/launchroom/daemon/internal/traffic"
)

const (
	reconcileInterval = 30 * time.Second
	minReportGap      = time.Second
	// taskShutdownGrace lets running tasks finish when launchroomd stops
	// (SIGTERM); unfinished ones are released for another attempt. Keep it
	// below the container stop timeout (deploy/daemon/compose.yml stop_grace_period).
	taskShutdownGrace = 20 * time.Second
	caddyCacheDir     = "caddy"
	buildsDir         = "builds"
)

// daemonRuntime owns the long-running loops after enrollment: reconcile,
// reports, tasks and the on-demand TLS ask endpoint.
type daemonRuntime struct {
	cfg      config.Config
	serverID state.ServerID
	client   *controlplane.Client
	store    *state.Store
	docker   docker.Client
	monitor  *health.Monitor
	rec      *reconcile.Reconciler
	ask      *caddy.Ask
	log      *slog.Logger
	now      func() time.Time

	// contactOnce marks this process running and resumes a pending self-update (selfupdate.go).
	contactOnce sync.Once

	reconcileKick chan struct{}
	reportKick    chan struct{}
	// stateKick makes the state syncer fetch now (realtime nudges).
	stateKick chan struct{}
	seq       atomic.Uint64
	// nudgeFallback is the nudge config from enrollment (docs/03 §4).
	nudgeFallback *state.NudgeConfig

	// Host health and disk cleanup (docs/04 §8).
	collector *hostinfo.Collector
	cleaner   *cleanup.Cleaner
	health    atomic.Pointer[state.HostHealth]
	// Hardware facts (sent when changed/unacknowledged) and usage metrics
	// (docs/design/direction.md §7.3, §8.2).
	hardware hostinfo.HardwareTracker
	metrics  *metrics.Collector
	// traffic aggregates the edge Caddy's access log into request metrics (docs/15 §1).
	traffic *traffic.Collector

	// Registry access and builds (docs/04 §5, docs/05 §6).
	tokens     *registry.Tokens
	regFwd     *registry.Forwarder // 127.0.0.1:5000 → Zot
	buildFwd   *registry.Forwarder // build network gateway:5000 → Zot (for buildkitd)
	buildkit   *buildkitd.Manager  // nil when the Docker client cannot run system ops (tests)
	builder    *build.Builder
	fwdOn      bool
	buildFwdOn bool
	sealingKey *ecdh.PrivateKey
	policy     policy.Config

	// firewall is the nftables network isolation (docs/05 §1).
	firewall *firewall.Manager
	// hostRT is rootless Docker / dockerd-guard and the edge ports (D49).
	hostRT hostRuntime

	// Mesh client and the control-plane host's mesh-side registry forwarder (docs/05 §2, §6).
	mesh        *mesh.Manager
	meshFwd     *registry.Forwarder
	meshFwdStop context.CancelFunc
	// Certificate storage on the mesh (control-plane host, docs/05 §5).
	certFwd     *registry.Forwarder
	certFwdStop context.CancelFunc
	// ambassador runs lr-system-ambassador (docs/05 §3).
	ambassador    *ambassadord.Manager
	meshRefusedMu sync.Mutex
	meshRefused   []string

	// Private-network link measurements (docs/05 §2.1): the prober, the
	// server answering peers' probes and its listener on the mesh IP.
	links    *linkprobe.Prober
	linkSrv  *linkprobe.Server
	linkAddr string
	linkStop context.CancelFunc

	// ingress runs the edge Caddy; it also reads certificate state (docs/05 §5).
	ingress *caddy.Manager
	// clientCert keeps the client certificate for the dashboard (mTLS,
	// docs/03 §4.3); nil in tests without one.
	clientCert *clientcert.Manager
	// follow moves requests to the dashboard's canonical origin from the
	// NodeState once it verifies (docs/03 §4.2).
	follow *controlplane.Follower

	// rejected is the latest NodeState the daemon refused (Report.stateRejected).
	rejected atomic.Pointer[state.Rejection]
	// netHolds keeps environment networks of running one-off tasks from
	// being removed by the reconciler (shared by both).
	netHolds docker.NetworkHolds
	// logship ships runtime logs of workloads with shipLogs (runtime log retention).
	logship *logship.Manager
	// backupEngine runs backup tasks; walArchive ships WAL of workloads with
	// a walArchive (point-in-time recovery, docs/14 §10). Both nil without exec support.
	backupEngine *backup.Engine
	walArchive   *backup.WalArchiver
}

func newRuntime(ctx context.Context, cfg config.Config, id *identity.Identity, serverID state.ServerID,
	client *controlplane.Client, store *state.Store, log *slog.Logger, d deps,
) (*daemonRuntime, error) {
	rt := &daemonRuntime{
		cfg: cfg, serverID: serverID, client: client, store: store, log: log, now: d.now,
		reconcileKick: make(chan struct{}, 1), reportKick: make(chan struct{}, 1), stateKick: make(chan struct{}, 1), ask: &caddy.Ask{},
	}
	if rt.now == nil {
		rt.now = time.Now
	}
	// Report sequence numbers must keep increasing across restarts (the
	// dashboard drops older ones), so they start from the clock.
	rt.seq.Store(uint64(max(rt.now().UnixMicro(), 1))) //nolint:gosec // positive

	rt.follow = controlplane.NewFollower(controlplane.FollowOptions{
		Client: client, DataDir: cfg.DataDir, Off: cfg.ControlPlaneFollowOff, Now: rt.now,
		Log: log.With("component", "controlplane"),
		// The forward-auth allow-list and the realtime endpoints follow the
		// adopted origin: re-render Caddy and report now.
		OnChange: func() {
			rt.kickReconcile()
			rt.kickReport()
			if rt.clientCert != nil {
				rt.clientCert.Kick() // an https origin may need a certificate now
			}
		},
	})
	rt.follow.Load()
	rt.tokens = registry.NewTokens(client)
	newDocker := d.docker
	if newDocker == nil {
		newDocker = func(s state.ServerID) (docker.Client, error) {
			return docker.NewEngine(docker.EngineOptions{
				ServerID: string(s), UserAgent: "launchroomd/" + version,
				// Pulls from the launchroom registry get a short-lived, repository-scoped token.
				Auth: rt.tokens.PullAuth(registry.DefaultAddress),
				// Mirror warm-up before pulls (docs/04 §11 "Pulls").
				Warm: rt.tokens.Warm(registry.DefaultAddress, nil, nil),
			})
		}
	}
	dc, err := newDocker(serverID)
	if err != nil {
		return nil, err
	}
	rt.docker = dc

	admin := d.caddyAdmin
	if admin == nil {
		admin = caddy.NewAdminClient(caddy.DefaultAdminListen)
	}
	pol := policy.DefaultConfig()
	pol.LocalUpstreams = cfg.AllowedLocalUpstreams
	pol.CertStorageAddrs = cfg.CertStorageAllow
	for comp, digests := range cfg.SystemImageDigests {
		pol.SystemImageDigests[comp] = append(pol.SystemImageDigests[comp], digests...)
	}
	rt.policy = pol
	priv := id.SealingPrivateKey()
	rt.traffic = traffic.New(traffic.Options{OnMinute: rt.kickReport, Now: rt.now, Log: log.With("component", "traffic")})
	ingress := caddy.NewManager(caddy.ManagerOptions{
		ServerID: string(serverID), Docker: dc, Admin: admin, Policy: pol, Ask: rt.ask,
		CacheDir: filepath.Join(cfg.DataDir, caddyCacheDir), Log: log.With("component", "ingress"),
		Spec: func() *state.IngressSpec {
			if cur := store.Current(); cur != nil {
				return cur.State.Ingress
			}
			return nil
		},
		OpenSecret: func(s state.Sealed, purpose state.IngressSecretPurpose) (map[string]secret.Secret, error) {
			return sealed.OpenIngressSecret(s, priv, serverID, purpose)
		},
		ForwardAuthOrigins: func() []string {
			return forwardAuthOrigins(cfg.ControlPlaneURL, rt.follow.Adopted(), cfg.AllowedLocalUpstreams)
		},
		Render: caddy.Options{
			AdminListen: caddy.DefaultAdminListen, AskURL: "http://" + cfg.AskAddr.String() + caddy.AskPath,
			HTTPPort: 80, HTTPSPort: 443, RedirectHTTPSPort: cfg.HTTPSRedirectPort,
			ForwardAuthOrigins: forwardAuthOrigins(cfg.ControlPlaneURL, nil, cfg.AllowedLocalUpstreams),
			AccessLogAddress:   cfg.AccessLogAddr.String(),
		},
	})
	rt.firewall = firewall.New(firewall.Options{
		Mode: firewall.Mode(cfg.Firewall), Backend: d.firewall, Networks: dc.ListNetworks,
		BuildNetwork: buildkitd.NetworkName, BuildGateway: cfg.BuildSubnet.Addr().Next(), RegistryPort: buildkitd.RegistryPort,
		AllowPrivate: cfg.FirewallAllowPrivate, MeshInterface: mesh.Interface, Log: log.With("component", "firewall"), Now: rt.now,
		AmbassadorNetwork: ambassadord.NetworkName, Mesh: rt.meshRules,
	})
	rt.ingress = ingress
	rt.monitor = health.NewMonitor(ctx, health.MonitorOptions{OnChange: rt.kickReconcile})
	rt.sealingKey = priv
	rt.rec = reconcile.New(reconcile.Options{
		ServerID: serverID, Docker: dc, Policy: pol, Health: rt.monitor, Ports: d.ports, Ingress: ingress,
		EnvHashKey: reconcile.EnvHashKey(priv.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, priv, serverID, s.ID)
		},
		OnProgress: rt.kickReport,
		Isolate:    rt.firewall.Sync,
		MeshIP:     rt.meshIP,
		BeforeNetworkRemove: func(ctx context.Context, network string) error {
			if rt.ambassador == nil {
				return nil
			}
			return rt.ambassador.Detach(ctx, network)
		},
		Holds: &rt.netHolds,
		Log:   log.With("component", "reconcile"),
	})
	rt.logship = logship.New(logship.Options{
		Docker: dc, Client: client, Dir: filepath.Join(cfg.DataDir, "logship"), Log: log.With("component", "logship"), Now: rt.now,
	})
	rt.setupBuilds(ctx, dc, pol)
	rt.setupMesh(dc, pol, priv)
	rt.setupLinkProbe(id)
	rt.setupHealth(dc)
	rt.setupBackups()
	return rt, nil
}

// setupBuilds prepares the registry forwarders, the buildkitd manager and the
// build pipeline. buildkitd itself only runs when the NodeState enables it.
func (rt *daemonRuntime) setupBuilds(ctx context.Context, dc docker.Client, pol policy.Config) {
	rt.regFwd = registry.NewForwarder(registry.DefaultAddress, "", rt.log.With("component", "registry"))
	ops, ok := dc.(docker.SystemOps)
	if !ok {
		return
	}
	memMB, cpus := rt.cfg.BuildkitMemoryMB, rt.cfg.BuildkitCPUs
	if memMB == 0 {
		ictx, cancel := context.WithTimeout(ctx, 5*time.Second)
		if info, err := dc.Info(ictx); err == nil && info.MemoryMB > 0 {
			memMB = info.MemoryMB * 3 / 4
		}
		cancel()
	}
	rt.buildkit = buildkitd.New(buildkitd.Options{
		ServerID: string(rt.serverID), Docker: dc, Ops: ops, Policy: pol, Subnet: rt.cfg.BuildSubnet,
		MaxParallelism: max(2, runtime.NumCPU()), CPUs: cpus, MemoryMB: memMB,
		GC:  buildkitd.GC{MaxUsedSpace: "60GB", ReservedSpace: "10GB", MinFreeSpace: "15%"},
		Log: rt.log.With("component", "buildkitd"),
	})
	gw := netip.AddrPortFrom(rt.buildkit.Gateway(), buildkitd.RegistryPort)
	rt.buildFwd = registry.NewForwarder(gw.String(), "", rt.log.With("component", "registry-build"))
	rt.builder = build.New(build.Options{
		BuildKit:         rt.buildkit.Client,
		Tokens:           rt.tokens,
		RegistryHost:     buildkitd.RegistryHost(),
		PublicRegistry:   registry.DefaultAddress,
		WorkDir:          filepath.Join(rt.cfg.DataDir, buildsDir),
		InsecureGitHosts: rt.cfg.InsecureGitHosts,
		Isolate:          rt.firewall.Sync,
		Log:              rt.log.With("component", "build"),
	})
}

// meshIP is this server's mesh IPv4 once the mesh client reports it AND
// the address is configured on a local interface: Docker can only bind
// published ports to it then (invalid otherwise).
func (rt *daemonRuntime) meshIP() netip.Addr {
	if rt.mesh == nil {
		return netip.Addr{}
	}
	ip := rt.mesh.IPv4()
	if !ip.IsValid() || !localAddr(ip) {
		return netip.Addr{}
	}
	return ip
}

// localAddr reports whether ip is assigned to an interface of this network namespace.
func localAddr(ip netip.Addr) bool {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return false
	}
	for _, a := range addrs {
		if p, err := netip.ParsePrefix(a.String()); err == nil && p.Addr().Unmap() == ip {
			return true
		}
	}
	return false
}

// syncSystem converges the mesh, the ambassador, the registry forwarders and
// buildkitd to the NodeState.
func (rt *daemonRuntime) syncSystem(ctx context.Context, ns *state.NodeState) {
	rt.syncMesh(ctx, ns)
	rt.syncAmbassador(ctx, ns)
	upstream := rt.syncRegistry(ctx, ns)
	if rt.buildkit == nil {
		return
	}
	if err := rt.buildkit.Sync(ctx, ns.System.Buildkit); err != nil && ctx.Err() == nil {
		rt.log.Warn("buildkitd not converged", "err", err)
	}
	// Cover the build network right after buildkitd (re)created it; errors
	// are logged and reported by the manager.
	_ = rt.firewall.Sync(ctx)
	if !rt.buildkit.Enabled() {
		rt.setBuildPlatforms(nil)
		return
	}
	// What this builder can build, emulated platforms included (D53);
	// asked once per buildkitd container, retried on the next pass.
	pctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	if p := rt.buildkit.Platforms(pctx); p != nil {
		rt.setBuildPlatforms(p)
	}
	cancel()
	buildUp := upstream
	if buildUp == "" {
		buildUp = registry.DefaultAddress // Zot itself listens on 127.0.0.1:5000 (control-plane host)
	}
	rt.buildFwd.SetUpstream(buildUp)
	if !rt.buildFwdOn {
		rt.buildFwdOn = rt.serveForwarder(ctx, rt.buildFwd, "build registry forwarder")
	}
}

// syncRegistry points the 127.0.0.1:5000 registry forwarder at the
// NodeState's registry (or the configured upstream) and starts it. It runs
// before the reconciler too: the first state that brings workloads to a
// server can be the one that names its registry (a server that just joined,
// or the target of a move, docs/14 §13), and their pulls must not race the
// forwarder. Returns the upstream ("" = none).
func (rt *daemonRuntime) syncRegistry(ctx context.Context, ns *state.NodeState) string {
	upstream := string(ns.System.Registry.ForwardTo)
	if upstream == "" {
		upstream = rt.cfg.RegistryUpstream
	}
	rt.regFwd.SetUpstream(upstream)
	if upstream != "" && !rt.fwdOn {
		rt.fwdOn = rt.serveForwarder(ctx, rt.regFwd, "registry forwarder")
	}
	return upstream
}

// serveForwarder binds and serves f in the background; it reports whether the bind worked.
func (rt *daemonRuntime) serveForwarder(ctx context.Context, f *registry.Forwarder, name string) bool {
	var lc net.ListenConfig
	l, err := lc.Listen(ctx, "tcp", f.Addr())
	if err != nil {
		rt.log.Warn(name+" cannot listen; retrying on the next pass", "addr", f.Addr(), "err", err)
		return false
	}
	rt.log.Info(name+" listening", "addr", f.Addr(), "upstream", f.Upstream())
	go func() {
		if err := f.ServeListener(ctx, l); err != nil {
			rt.log.Error(name+" stopped", "err", err)
		}
	}()
	return true
}

func (rt *daemonRuntime) kickReconcile() { notify(rt.reconcileKick) }
func (rt *daemonRuntime) kickReport()    { notify(rt.reportKick) }

func notify(ch chan struct{}) {
	select {
	case ch <- struct{}{}:
	default:
	}
}

// run starts every loop, runs sync in the foreground and waits for all loops
// after ctx is cancelled. Workload containers keep running when the daemon stops.
func (rt *daemonRuntime) run(ctx context.Context, syncState func(context.Context) error, taskInterval time.Duration) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	var wg sync.WaitGroup
	start := func(f func()) {
		wg.Add(1)
		go func() { defer wg.Done(); f() }()
	}
	start(func() {
		if err := rt.ask.Serve(ctx, rt.cfg.AskAddr.String()); err != nil {
			rt.log.Error("on-demand TLS ask endpoint failed", "err", err, "addr", rt.cfg.AskAddr.String())
		}
	})
	// Isolation first: existing containers are covered before anything else
	// runs (errors are logged and reported; workloads wait for it).
	_ = rt.firewall.Sync(ctx)
	start(func() { rt.reconcileLoop(ctx) })
	start(func() { rt.scheduleLoop(ctx) })
	start(func() { rt.reportLoop(ctx) })
	runner := tasks.New(tasks.Options{
		Client: rt.client, Interval: taskInterval, Log: rt.log.With("component", "tasks"),
		Handlers: rt.handlers(), ShutdownGrace: taskShutdownGrace,
	})
	start(func() { runner.Run(ctx) })
	runners := []*tasks.Runner{runner}
	if sessions := rt.sessionRunner(taskInterval); sessions != nil {
		start(func() { sessions.Run(ctx) })
		runners = append(runners, sessions)
	}
	start(func() { rt.nudges(ctx, runners...) })
	start(func() { rt.healthLoop(ctx) })
	start(func() { rt.metrics.Run(ctx) })
	start(func() { rt.traffic.Run(ctx) })
	start(func() {
		// Request metrics are optional: without the listener Caddy drops
		// its access lines (soft_start) and everything else runs as usual.
		if err := rt.traffic.Serve(ctx, rt.cfg.AccessLogAddr.String()); err != nil {
			rt.log.Warn("access log listener failed; no request metrics", "err", err, "addr", rt.cfg.AccessLogAddr.String())
		}
	})
	start(func() { rt.links.Run(ctx, rt.kickReport) })
	start(func() { rt.ingress.RunCertificates(ctx, 0) })
	start(func() { rt.follow.Run(ctx) })
	if rt.clientCert != nil {
		start(func() { rt.clientCert.Run(ctx) })
	}
	start(func() { rt.runtimeLoop(ctx) })
	rt.kickReconcile()

	err := syncState(ctx)
	cancel()
	wg.Wait()
	if rt.walArchive != nil {
		rt.walArchive.Stop(ctx)
	}
	rt.monitor.Close()
	return err
}

// nudges keeps the realtime nudge subscription (docs/03 §4): "state.changed"
// fetches the desired state now, "task.available"/"logs.requested" lease now.
// After every (re)subscription both happen once, since nudges sent while
// disconnected are lost. Polling continues regardless.
func (rt *daemonRuntime) nudges(ctx context.Context, runners ...*tasks.Runner) {
	nudge.Run(ctx, nudge.Options{
		CP: rt.client, ControlPlaneURL: rt.cfg.ControlPlaneURL, ControlPlaneBase: rt.client.ActiveBase,
		ServerID: rt.serverID, Fallback: rt.nudgeFallback,
		DaemonVersion: version, Log: rt.log.With("component", "nudge"),
		OnEvent: func(event string) {
			rt.log.Info("nudge received", "event", event)
			switch event {
			case state.NudgeStateChanged:
				notify(rt.stateKick)
			case state.NudgeTaskAvailable, state.NudgeLogsRequested:
				for _, r := range runners {
					r.Kick()
				}
			}
		},
		OnSubscribed: func() {
			notify(rt.stateKick)
			for _, r := range runners {
				r.Kick()
			}
		},
	})
}

func (rt *daemonRuntime) reconcileLoop(ctx context.Context) {
	for {
		select {
		case <-ctx.Done():
			return
		case <-rt.reconcileKick:
		case <-time.After(jitter(reconcileInterval)):
		}
		cur := rt.store.Current()
		if cur == nil {
			continue
		}
		meshIP := rt.meshIP()
		rt.follow.Want(cur.State.System.ControlPlane.URL)
		rt.traffic.SetRoutes(cur.State.Routes)
		rt.syncRegistry(ctx, cur.State)
		if err := rt.rec.Reconcile(ctx, cur.State); err != nil && ctx.Err() == nil {
			rt.log.Warn("reconcile pass incomplete", "version", cur.State.Version, "err", err)
		}
		rt.syncSystem(ctx, cur.State)
		rt.logship.Sync(ctx, cur.State)
		if rt.walArchive != nil {
			rt.walArchive.Sync(ctx, cur.State)
		}
		if rt.meshIP() != meshIP {
			// The mesh address appeared or changed: publish mesh ports and
			// update the firewall right away (docs/05 §3).
			rt.log.Info("mesh address changed; reconciling again", "from", meshIP, "to", rt.meshIP())
			_ = rt.firewall.Sync(ctx)
			rt.kickReconcile()
		}
		rt.kickReport()
	}
}

// scheduleLoop starts scheduled workloads (cron services) at every due
// minute, a second after the minute begins; works offline like the reconciler.
func (rt *daemonRuntime) scheduleLoop(ctx context.Context) {
	for {
		now := time.Now()
		wait := now.Truncate(time.Minute).Add(time.Minute + time.Second).Sub(now)
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
		if rt.rec.RunDue(ctx, time.Now()) {
			rt.kickReconcile()
		}
	}
}

func (rt *daemonRuntime) reportLoop(ctx context.Context) {
	var last time.Time
	for {
		select {
		case <-ctx.Done():
			return
		case <-rt.reportKick:
		}
		if wait := minReportGap - time.Since(last); wait > 0 {
			select {
			case <-ctx.Done():
				return
			case <-time.After(wait):
			}
		}
		last = time.Now()
		rep := rt.buildReport(ctx)
		if rep == nil {
			continue
		}
		if err := rt.client.Report(ctx, rep); err != nil {
			if ctx.Err() == nil {
				rt.log.Warn("sending report failed", "err", err, "seq", rep.Seq)
			}
			continue
		}
		// Accepted: these minutes and facts are delivered.
		rt.metrics.Ack(rep.Metrics)
		if rep.Metrics != nil {
			rt.traffic.Ack(rep.Metrics.Requests)
		}
		if rep.Host != nil {
			rt.hardware.Ack(rep.Host.Hardware, rt.now())
		}
	}
}

func (rt *daemonRuntime) buildReport(ctx context.Context) *state.Report {
	version, workloads, ingress := rt.rec.Snapshot()
	rejected := rt.rejected.Load()
	if version == 0 && rejected == nil {
		return nil
	}
	return &state.Report{
		TypeMeta:        state.NewTypeMeta(state.KindReport),
		ServerID:        rt.serverID,
		ObservedAt:      rt.now().UTC(),
		Seq:             rt.seq.Add(1),
		Full:            true, // reports are always complete snapshots
		AppliedVersion:  version,
		Workloads:       workloads,
		Host:            rt.hostStatus(ctx),
		Ingress:         ingress,
		StateRejected:   rejected,
		VolumeDeletions: rt.rec.VolumeDeletions(),
		Metrics:         rt.pendingMetrics(),
		WalArchives:     rt.walArchiveStatus(),
	}
}

// pendingMetrics merges the undelivered usage minutes and request minutes.
func (rt *daemonRuntime) pendingMetrics() *state.ReportMetrics {
	m := rt.metrics.Pending()
	reqs := rt.traffic.Pending()
	if len(reqs) == 0 {
		return m
	}
	if m == nil {
		m = &state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}}
	}
	if over := len(reqs) - state.MaxRequestMetricSamples; over > 0 {
		reqs = reqs[over:]
	}
	m.Requests = reqs
	return m
}

func (rt *daemonRuntime) hostStatus(ctx context.Context) *state.HostStatus {
	h := &state.HostStatus{CPUs: max(1, min(runtime.NumCPU(), 4096)), DaemonVersion: state.Semver(version)}
	ictx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	if info, err := rt.docker.Info(ictx); err == nil {
		if info.CPUs > 0 {
			h.CPUs = min(info.CPUs, 4096)
		}
		h.MemoryMB = max(info.MemoryMB, 0)
		if v := state.Label(info.Version); v.Validate() == nil {
			h.DockerVersion = v
		}
	}
	if fs, ok := hostinfo.Filesystem(rt.cfg.DataDir); ok {
		h.DiskTotalMB, h.DiskFreeMB = fs.TotalMB, fs.FreeMB
	}
	h.Health = rt.health.Load()
	h.Mesh = rt.mesh.Status()
	h.Hardware = rt.hardware.ForReport(rt.now())
	h.Links = rt.links.Snapshot()
	h.Features = rt.features()
	h.ControlPlane = rt.follow.Status()
	h.Runtime = rt.runtimeStatus(h)
	return h
}

// Host health and cleanup cadence (docs/04 §8).
const (
	healthInterval       = 5 * time.Minute
	cleanupFirstAfter    = 15 * time.Minute
	cleanupInterval      = 24 * time.Hour
	cleanupPressureGap   = 30 * time.Minute
	diskPressurePercent  = 85
	buildkitQueryTimeout = 2 * time.Minute
)

// setupHealth prepares the host health collector and the disk cleaner.
func (rt *daemonRuntime) setupHealth(dc docker.Client) {
	host, _ := dc.(docker.HostOps)
	rt.cleaner = cleanup.New(cleanup.Options{
		Docker: dc, Host: host, RegistryPrefix: registry.DefaultAddress + "/",
		DesiredImages: rt.desiredImages, PruneBuildCache: rt.buildCachePrune,
		Log: rt.log.With("component", "cleanup"), Now: rt.now,
	})
	rt.collector = hostinfo.New(hostinfo.Options{
		DataDir: rt.cfg.DataDir, Docker: host, BuildCacheMB: rt.buildCacheMB, ClockSkew: rt.client.ClockSkew, Now: rt.now,
	})
	stats, _ := dc.(docker.StatsOps)
	rt.metrics = metrics.New(metrics.Options{
		Disk: metricsDisk(host, rt.cfg.DataDir), Containers: dc, Stats: stats,
		OnMinute: rt.kickReport, Now: rt.now, Log: rt.log.With("component", "metrics"),
	})
}

// metricsDisk returns the filesystem metrics report: Docker's root dir when
// launchroomd can see it (cached), else the data dir (like diskPressure).
func metricsDisk(host docker.HostOps, dataDir string) func(ctx context.Context) (state.FilesystemUsage, bool) {
	var root string
	return func(ctx context.Context) (state.FilesystemUsage, bool) {
		if root == "" && host != nil {
			if r, err := host.RootDir(ctx); err == nil && filepath.IsAbs(r) {
				root = r
			}
		}
		if root != "" {
			if fs, ok := hostinfo.Filesystem(root); ok {
				return fs, true
			}
		}
		return hostinfo.Filesystem(dataDir)
	}
}

// collectHardware refreshes the hardware facts (at start and with every
// health snapshot); a change is sent with the next report.
func (rt *daemonRuntime) collectHardware(ctx context.Context) {
	h := hostinfo.Hardware(ctx, hostinfo.HardwareOptions{
		Engine: rt.docker.Info, Timezone: rt.cfg.HostTimezone, Kernel: hostinfo.KernelRelease, HostModel: rt.cfg.HostModel,
	})
	if h == nil {
		return
	}
	if raw, err := json.Marshal(h); err == nil && len(raw) > maxHardwareBytes {
		rt.log.Warn("hardware facts are unexpectedly large; not reporting them", "bytes", len(raw))
		return
	}
	rt.hardware.Set(h)
}

// maxHardwareBytes bounds the hardware facts (well above the ~0.5 KB seen in practice).
const maxHardwareBytes = 2048

// desiredImages are the images of the current NodeState (never cleaned up).
func (rt *daemonRuntime) desiredImages() []string {
	cur := rt.store.Current()
	if cur == nil {
		return nil
	}
	out := make([]string, 0, len(cur.State.Workloads)+2)
	for _, w := range cur.State.Workloads {
		out = append(out, string(w.Image))
	}
	for _, sys := range []state.SystemContainer{cur.State.System.Caddy, cur.State.System.Buildkit} {
		if sys.Image != "" {
			out = append(out, string(sys.Image))
		}
	}
	if img := cur.State.System.Tailscale.Image; img != "" {
		out = append(out, string(img))
	}
	return out
}

// buildCachePrune prunes the BuildKit cache by age while keeping keepBytes
// (never --all), when this server runs buildkitd.
func (rt *daemonRuntime) buildCachePrune(context.Context) cleanup.BuildCachePrune {
	if rt.buildkit == nil || !rt.buildkit.Enabled() {
		return nil
	}
	return func(ctx context.Context, keepDuration time.Duration, keepBytes int64) (int64, error) {
		ctx, cancel := context.WithTimeout(ctx, buildkitQueryTimeout)
		defer cancel()
		c, err := rt.buildkit.Client(ctx)
		if err != nil {
			return 0, err
		}
		defer func() { _ = c.Close() }()
		ch := make(chan bkclient.UsageInfo)
		var freed int64
		done := make(chan struct{})
		go func() {
			defer close(done)
			for u := range ch {
				freed += u.Size
			}
		}()
		err = c.Prune(ctx, ch, bkclient.WithKeepOpt(keepDuration, keepBytes, 0, 0))
		close(ch)
		<-done
		return freed, err
	}
}

// buildCacheMB is the size of the BuildKit cache (ok=false without buildkitd).
func (rt *daemonRuntime) buildCacheMB(ctx context.Context) (int64, bool) {
	if rt.buildkit == nil || !rt.buildkit.Enabled() {
		return 0, false
	}
	ctx, cancel := context.WithTimeout(ctx, buildkitQueryTimeout)
	defer cancel()
	c, err := rt.buildkit.Client(ctx)
	if err != nil {
		return 0, false
	}
	defer func() { _ = c.Close() }()
	usage, err := c.DiskUsage(ctx)
	if err != nil {
		return 0, false
	}
	var total int64
	for _, u := range usage {
		total += max(u.Size, 0)
	}
	return total >> 20, true
}

// healthLoop collects host health every few minutes and runs the disk
// cleanup on schedule (daily) and under disk pressure (≥ 85 % used).
func (rt *daemonRuntime) healthLoop(ctx context.Context) {
	nextCleanup := rt.now().Add(jitter(cleanupFirstAfter))
	var lastCleanup time.Time
	wait := 10 * time.Second // first snapshot soon after start
	rt.collectHardware(ctx)
	for {
		if sleep(ctx, wait) != nil {
			return
		}
		wait = jitter(healthInterval)
		rt.collectHardware(ctx)
		h := rt.collectHealth(ctx)
		now := rt.now()
		trigger := state.CleanupTrigger("")
		switch {
		case !now.Before(nextCleanup):
			trigger = state.CleanupScheduled
			nextCleanup = now.Add(jitter(cleanupInterval))
		case diskPressure(h) && now.Sub(lastCleanup) >= cleanupPressureGap:
			trigger = state.CleanupDiskPressure
		}
		if trigger == "" {
			continue
		}
		rt.cleaner.Run(ctx, trigger)
		lastCleanup = rt.now()
		rt.collectHealth(ctx)
	}
}

func (rt *daemonRuntime) collectHealth(ctx context.Context) *state.HostHealth {
	h := rt.collector.Collect(ctx)
	h.Cleanup = rt.cleaner.Last()
	h.Firewall = rt.firewall.Status()
	if err := (&state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: rt.serverID, ObservedAt: h.CollectedAt, Seq: 1,
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: state.Semver(version), Health: h}}).Validate(); err != nil {
		rt.log.Warn("host health snapshot is invalid; not reporting it", "err", err)
		return h
	}
	rt.health.Store(h)
	rt.kickReport()
	return h
}

// diskPressure reports whether Docker's filesystem (or the data dir's, when
// Docker's root is not visible) is at least 85 % full.
func diskPressure(h *state.HostHealth) bool {
	fs := h.DockerDisk
	if fs == nil {
		fs = h.DataDisk
	}
	return fs != nil && fs.TotalMB > 0 && (fs.TotalMB-fs.FreeMB)*100 >= diskPressurePercent*fs.TotalMB
}

// handlers are the task types this daemon runs.
func (rt *daemonRuntime) handlers() map[state.TaskType]tasks.Handler {
	h := map[state.TaskType]tasks.Handler{
		state.TaskLogs: tasks.LogsHandler(rt.docker, rt.client),
		state.TaskRunOnce: tasks.RunOnceHandler(tasks.RunOnceOptions{
			ServerID: rt.serverID, SealingKey: rt.sealingKey, Docker: rt.docker, Client: rt.client,
			Policy: rt.policy, Forget: rt.tokens.Forget, Isolate: rt.firewall.Sync, Holds: &rt.netHolds,
			Ports: func(pid int) []int {
				ls, err := health.ProcScanner{}.Listeners(pid)
				if err != nil {
					return nil
				}
				return health.Ports(ls)
			},
		}),
	}
	if rt.builder != nil {
		bh := tasks.BuildHandler(tasks.BuildOptions{
			ServerID: rt.serverID, SealingKey: rt.sealingKey, Builder: rt.builder, Client: rt.client,
			Slots: make(chan struct{}, rt.cfg.BuildSlots), Forget: rt.tokens.Forget,
		})
		h[state.TaskBuild] = bh
		h[state.TaskPlan] = bh
	}
	rt.addBackupHandlers(h)
	rt.addUpdateHandler(h)
	return h
}

// forwardAuthOrigins are the origins protected routes may use for forward auth
// (local policy floor, docs/03 §8): the configured control-plane URL's
// origin, the adopted canonical origin once it verified (docs/03 §4.2; never
// an unverified pushed one) and http://<ip:port> of every allowed local
// upstream (the control-plane host's dashboard on 127.0.0.1:8000). Ports are
// always explicit.
func forwardAuthOrigins(cp, adopted *url.URL, local []netip.AddrPort) []string {
	var out []string
	for _, u := range []*url.URL{cp, adopted} {
		if o := explicitOrigin(u); o != "" && !slices.Contains(out, o) {
			out = append(out, o)
		}
	}
	for _, ap := range local {
		out = append(out, "http://"+ap.String())
	}
	return out
}

// explicitOrigin is "scheme://host:port" with the port always present ("" for
// anything but http(s) with a host).
func explicitOrigin(u *url.URL) string {
	if u == nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" {
		return ""
	}
	port := u.Port()
	if port == "" {
		port = "80"
		if u.Scheme == "https" {
			port = "443"
		}
	}
	return u.Scheme + "://" + net.JoinHostPort(strings.ToLower(u.Hostname()), port)
}
