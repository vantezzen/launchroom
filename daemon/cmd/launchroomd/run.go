package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/clientcert"
	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/enrollment"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	defaultStateInterval = 30 * time.Second
	defaultTaskInterval  = 10 * time.Second
	enrollBackoffMin     = 2 * time.Second
	enrollBackoffMax     = time.Minute
	stateFile            = "state.json"
)

// deps are the process-level dependencies, replaceable in tests.
type deps struct {
	transport http.RoundTripper // nil = controlplane default
	hostname  func() (string, error)
	now       func() time.Time
	// docker returns the Docker client (nil = the local Engine from DOCKER_HOST).
	docker func(serverID state.ServerID) (docker.Client, error)
	// caddyAdmin is the Caddy admin client (nil = 127.0.0.1:2019).
	caddyAdmin caddy.Admin
	// ports discovers listening ports (nil = /proc).
	ports health.PortScanner
	// firewall programs nftables (nil = the kernel via netlink).
	firewall firewall.Backend
}

func defaultDeps() deps {
	return deps{hostname: os.Hostname, now: time.Now}
}

// run is the daemon's lifetime. It returns nil on graceful shutdown (ctx cancelled).
func run(ctx context.Context, cfg config.Config, log *slog.Logger, refresh <-chan os.Signal, d deps) error {
	id, created, err := identity.LoadOrCreate(cfg.KeysDir())
	if err != nil {
		return err
	}
	log.Info("identity ready",
		"created", created,
		"fingerprint", identity.Fingerprint(id.SigningPublicKey()),
		"signingKey", identity.FormatPublicKey(id.SigningPublicKey()))

	rec, err := enrollment.Load(cfg.DataDir)
	if err != nil {
		return err
	}
	switch {
	case rec == nil && cfg.JoinToken.IsZero():
		return fmt.Errorf("launchroomd is not enrolled and no join token is configured (set $%s or $%s)", config.EnvJoinToken, config.EnvJoinTokenFile)
	case rec == nil:
		rec, err = enrollWithRetry(ctx, cfg, id, log, d)
		if err != nil {
			return err
		}
	case !cfg.JoinToken.IsZero():
		log.Info("already enrolled; ignoring the configured join token", "serverId", rec.ServerID)
	}
	if rec.ControlPlaneURL != cfg.ControlPlaneURL.String() {
		log.Warn("control-plane URL differs from the one used at enrollment; using the configured one",
			"enrolledWith", rec.ControlPlaneURL, "configured", cfg.ControlPlaneURL.String())
	}

	trusted, err := rec.KeySet()
	if err != nil {
		return err
	}
	signer, err := id.NewSigner(string(rec.ServerID))
	if err != nil {
		return err
	}
	// Client certificate for the dashboard (mTLS, docs/03 §4.3): issued
	// over the signed channel, presented on every TLS connection to it.
	issuer := &clientCertIssuer{}
	certs, err := clientcert.New(clientcert.Options{
		Dir: cfg.KeysDir(), ServerID: rec.ServerID, Issuer: issuer, Now: d.now,
		Active: issuer.overHTTPS, OnRotate: issuer.rotated, Log: log.With("component", "clientcert"),
	})
	if err != nil {
		return err
	}
	client, err := controlplane.New(controlplane.Options{
		BaseURL: cfg.ControlPlaneURL, Signer: signer, Trusted: trusted, DaemonVersion: version, Transport: d.transport, Now: d.now,
		Log: log.With("component", "controlplane"), ClientCertificate: certs.GetClientCertificate,
	})
	if err != nil {
		return err
	}
	issuer.client.Store(client)

	store := state.NewStore(filepath.Join(cfg.DataDir, stateFile), trusted, rec.ServerID)
	if err := store.Load(); err != nil {
		// Fail closed: never act on a state we cannot verify; refetch from version 0.
		log.Error("persisted desired state rejected; starting without it", "err", err)
	} else if v := store.Version(); v > 0 {
		log.Info("loaded persisted desired state", "version", v)
	}

	interval := rec.PollIntervals.State.Std()
	if interval <= 0 {
		interval = defaultStateInterval
	}
	taskInterval := rec.PollIntervals.Tasks.Std()
	if taskInterval <= 0 {
		taskInterval = defaultTaskInterval
	}
	rt, err := newRuntime(ctx, cfg, id, rec.ServerID, client, store, log, d)
	if err != nil {
		return err
	}
	rt.nudgeFallback = rec.Nudge
	rt.clientCert = certs
	s := &controlplane.StateSyncer{Client: client, Store: store, ServerID: rec.ServerID, Log: log, OnNewState: rt.newState, Nudge: rt.stateKick,
		Rejected: &rt.rejected, OnRejected: rt.kickReport, Now: rt.now, OnContact: rt.controlPlaneContact}
	return rt.run(ctx, func(ctx context.Context) error { return s.Loop(ctx, interval, refresh) }, taskInterval)
}

// clientCertIssuer hands the client certificate manager the control-plane
// client (created after the manager, whose certificate it presents).
type clientCertIssuer struct {
	client atomic.Pointer[controlplane.Client]
}

// rotated drops idle connections that still carry the previous certificate.
func (i *clientCertIssuer) rotated() {
	if c := i.client.Load(); c != nil {
		c.CloseIdleConnections()
	}
}

func (i *clientCertIssuer) IssueClientCertificate(ctx context.Context, req *state.ClientCertificateRequest) (*state.ClientCertificate, error) {
	c := i.client.Load()
	if c == nil {
		return nil, errors.New("control-plane client not ready")
	}
	return c.IssueClientCertificate(ctx, req)
}

// overHTTPS: a certificate is only needed when the configured or the
// adopted control-plane URL is https (the control-plane host's own
// launchroomd talks to http://127.0.0.1:8000).
func (i *clientCertIssuer) overHTTPS() bool {
	c := i.client.Load()
	if c == nil {
		return false
	}
	if c.Configured().Scheme == "https" {
		return true
	}
	p := c.Preferred()
	return p != nil && p.Scheme == "https"
}

// enrollWithRetry enrolls, retrying transient failures with capped exponential
// backoff. Permanent failures (bad token, bad signature) stop the daemon.
func enrollWithRetry(ctx context.Context, cfg config.Config, id *identity.Identity, log *slog.Logger, d deps) (*enrollment.Record, error) {
	signer, err := id.NewSigner(identity.EnrollKeyID)
	if err != nil {
		return nil, err
	}
	client, err := controlplane.New(controlplane.Options{
		BaseURL: cfg.ControlPlaneURL, Signer: signer, DaemonVersion: version, Transport: d.transport,
	})
	if err != nil {
		return nil, err
	}
	req := &state.EnrollRequest{
		TypeMeta:         state.NewTypeMeta(state.KindEnrollRequest),
		Token:            cfg.JoinToken,
		SigningKey:       state.Ed25519Key(identity.FormatPublicKey(id.SigningPublicKey())),
		SealingKey:       state.X25519Key(identity.FormatSealingKey(id.SealingPublicKey())),
		DaemonVersion:    state.Semver(version),
		ProtocolVersions: []string{state.ProtocolVersionV1},
		Host:             hostFacts(d.hostname, cfg.HostOS),
	}
	// Total memory and hardware facts (device detection, docs/design/direction.md §7.3).
	if m, ok := hostinfo.ReadMemInfo("/proc"); ok {
		req.Host.MemoryMB = int(m.TotalMB)
	}
	req.Host.Hardware = hostinfo.Hardware(ctx, hostinfo.HardwareOptions{Timezone: cfg.HostTimezone, Kernel: hostinfo.KernelRelease, HostModel: cfg.HostModel})

	backoff := enrollBackoffMin
	for {
		log.Info("enrolling", "controlPlane", cfg.ControlPlaneURL.String())
		res, err := client.Enroll(ctx, req, cfg.PinnedCPKey)
		if err == nil {
			rec := &enrollment.Record{
				ServerID:         res.Response.ServerID,
				ControlPlaneURL:  cfg.ControlPlaneURL.String(),
				ControlPlaneKeys: res.Response.ControlPlaneKeys,
				Status:           res.Response.Status,
				PollIntervals:    res.Response.PollIntervals,
				Nudge:            res.Response.Nudge,
				EnrolledAt:       d.now().UTC(),
			}
			if err := enrollment.Save(cfg.DataDir, rec); err != nil {
				return nil, err
			}
			log.Info("enrolled", "serverId", rec.ServerID, "status", rec.Status)
			if rec.Status == state.ServerPendingApproval {
				log.Warn("server is pending approval in the dashboard; compare the fingerprint above before approving")
			}
			return rec, nil
		}
		if !controlplane.IsRetryable(err) {
			return nil, fmt.Errorf("enrollment failed: %w", err)
		}
		log.Warn("enrollment attempt failed; retrying", "err", err, "in", backoff.String())
		if err := sleep(ctx, controlplane.Jitter(backoff)); err != nil {
			return nil, err
		}
		backoff = min(backoff*2, enrollBackoffMax)
	}
}

var invalidLabelChars = regexp.MustCompile(`[^A-Za-z0-9._-]`)

// hostFacts are the enrolling host's facts. OS is the machine's: the
// installer's LR_HOST_OS on a Mac (D53), else the Linux launchroomd runs on.
func hostFacts(hostname func() (string, error), hostOS state.HostOS) state.HostFacts {
	name, err := hostname()
	if err != nil || name == "" {
		name = "unknown"
	}
	name = invalidLabelChars.ReplaceAllString(name, "-")
	if len(name) > 128 {
		name = name[:128]
	}
	osName := runtime.GOOS
	if hostOS != "" {
		osName = string(hostOS)
	}
	return state.HostFacts{
		Hostname: state.Label(name),
		OS:       state.Label(osName),
		Arch:     state.Label(runtime.GOARCH),
		CPUs:     max(1, min(runtime.NumCPU(), 4096)),
	}
}

func sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}
