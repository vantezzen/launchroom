// Package access runs temporary remote access (docs/03 §12, docs/09 R39,
// D61): "Connect via SSH" into one workload container and temporary
// access to a managed database. Each access is a pinned "access" task; its
// listener, keys and firewall rules exist only in this process.
package access

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/netip"
	"sort"
	"strings"
	"time"

	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// Options configures Handler.
type Options struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Docker     Docker
	Manager    *Manager
	// Workload returns a workload of the verified NodeState.
	Workload func(state.WorkloadID) (*state.WorkloadSpec, bool)
	// WorkloadEnv opens the sealed env of a workload in the verified NodeState.
	WorkloadEnv func(state.WorkloadID) (map[string]secret.Secret, error)
	// Allowed is the local policy (LR_REMOTE_ACCESS and LR_INTERACTIVE_SESSIONS).
	Allowed func(state.AccessKind) bool
	// CheckImage checks a volume access' helper image against the pinned
	// "files" digests (default: the built-in pins).
	CheckImage func(state.ImageRef) error
	// Dial reaches a database container (default: TCP).
	Dial func(ctx context.Context, addr netip.AddrPort) (net.Conn, error)
	// StatusEvery is how often the status is checked for idle and changes (default 1 s).
	StatusEvery time.Duration
	Log         *slog.Logger
	Now         func() time.Time
}

func (o *Options) defaults() {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.StatusEvery <= 0 {
		o.StatusEvery = time.Second
	}
	if o.Allowed == nil {
		o.Allowed = func(state.AccessKind) bool { return true }
	}
	if o.CheckImage == nil {
		o.CheckImage = func(ref state.ImageRef) error {
			return policy.CheckSystemImage(policy.SystemFiles, ref, policy.DefaultConfig())
		}
	}
	if o.Dial == nil {
		o.Dial = func(ctx context.Context, addr netip.AddrPort) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "tcp", addr.String())
		}
	}
}

// ErrRefused marks failures of the local policy.
var ErrRefused = errors.New("refused by this server's policy")

// Handler returns the tasks.Handler for "access" tasks.
func Handler(o Options) tasks.Handler {
	o.defaults()
	return func(ctx context.Context, t state.Task) (tasks.Result, error) {
		spec, ok := t.Spec.(*state.AccessSpec)
		if !ok {
			return tasks.Result{}, errors.New("access handler got a non-access spec")
		}
		res := &state.AccessResult{EndReason: state.AccessEndError}
		out := tasks.Result{Access: res}
		if err := spec.Validate(); err != nil {
			return out, err
		}
		if !o.Allowed(spec.Kind) {
			res.EndReason = state.AccessEndRefused
			return out, fmt.Errorf("%w: %s access is turned off on this server (LR_REMOTE_ACCESS)", ErrRefused, spec.Kind)
		}
		a := &run{o: o, t: t, spec: spec, res: res, log: o.Log.With("access", spec.Access, "kind", spec.Kind)}
		err := a.run(ctx)
		if errors.Is(err, ErrRefused) {
			res.EndReason = state.AccessEndRefused
		}
		return out, err
	}
}

type run struct {
	o    Options
	t    state.Task
	spec *state.AccessSpec
	res  *state.AccessResult
	log  *slog.Logger

	ep     *endpoint
	status state.AccessStatus
}

func (a *run) password() (string, error) {
	if a.spec.Password == nil {
		return "", nil
	}
	tok, err := sealed.OpenTaskToken(*a.spec.Password, a.o.SealingKey, a.o.ServerID, a.t.ID, state.PurposeAccess)
	if err != nil {
		return "", fmt.Errorf("access password: %w", err)
	}
	pw := tok.Reveal()
	if len(pw) < 24 || len(pw) > 128 || strings.ContainsAny(pw, "\x00\r\n") {
		return "", errors.New("access password: not a generated password")
	}
	return pw, nil
}

// target finds the running container of the spec's workload.
func (a *run) target(ctx context.Context) (docker.Container, *state.WorkloadSpec, error) {
	wl, ok := a.o.Workload(a.spec.Workload)
	if !ok {
		return docker.Container{}, nil, fmt.Errorf("%w: workload %s is not in this server's desired state", ErrRefused, a.spec.Workload)
	}
	cs, err := a.o.Docker.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(a.spec.Workload)})
	if err != nil {
		return docker.Container{}, nil, err
	}
	for _, c := range cs {
		l := c.Labels
		if l[docker.LabelManaged] != "true" || l[docker.LabelWorkload] != string(a.spec.Workload) ||
			l[docker.LabelSystem] != "" || l[docker.LabelTask] != "" || l[docker.LabelHelper] != "" || strings.HasPrefix(c.Name, "lr-system-") {
			continue
		}
		if !c.Running {
			return docker.Container{}, nil, fmt.Errorf("the container of %s is not running", a.spec.Workload)
		}
		return c, wl, nil
	}
	return docker.Container{}, nil, fmt.Errorf("%w: no workload container for %s on this server", ErrRefused, a.spec.Workload)
}

// autoShells is the console's automatic order (docs/03 §11.4).
var autoShells = []string{"/bin/bash", "/bin/ash", "/bin/sh"}

func (a *run) run(ctx context.Context) error {
	pw, err := a.password()
	if err != nil {
		return err
	}
	allow := make([]netip.Prefix, 0, len(a.spec.AllowFrom))
	for _, p := range a.spec.AllowFrom {
		allow = append(allow, p.Prefix())
	}
	deadline := a.t.Deadline
	if deadline.IsZero() {
		deadline = a.o.Now().Add(2 * time.Hour)
	}
	var c docker.Container
	var wl *state.WorkloadSpec
	if a.spec.Kind == state.AccessVolume {
		// The workload need not run: its volume does (a crash-looping
		// service is exactly when files need fixing).
		var ok bool
		if wl, ok = a.o.Workload(a.spec.Workload); !ok {
			return fmt.Errorf("%w: workload %s is not in this server's desired state", ErrRefused, a.spec.Workload)
		}
		vol, err := a.volumeTarget(ctx, wl)
		if err != nil {
			return err
		}
		release := a.o.Manager.Claim(a.spec.Access)
		defer release()
		id, owner, remove, err := a.startHelper(ctx, vol, deadline)
		if err != nil {
			return err
		}
		defer remove()
		c = docker.Container{ID: id}
		a.status.Owner = &owner
	} else {
		var err error
		if c, wl, err = a.target(ctx); err != nil {
			return err
		}
	}
	ctr := NewContainer(a.o.Docker, c.ID)
	a.ep = newEndpoint(allow, a.o.Now, a.log)

	var handle func(context.Context, net.Conn, netip.Addr)
	switch a.spec.Kind {
	case state.AccessSSH, state.AccessVolume:
		signer, hk, err := NewHostKey()
		if err != nil {
			return err
		}
		a.status.HostKey = hk
		var keys []ssh.PublicKey
		for _, k := range a.spec.AuthorizedKeys {
			pk, err := k.Parse()
			if err != nil {
				return err
			}
			keys = append(keys, pk)
		}
		shell, root := "", ""
		if a.spec.Kind == state.AccessVolume {
			// SFTP only, the volume as "/": no shell, no exec.
			root = HelperMount
		} else {
			for _, sh := range autoShells {
				if ok, err := a.o.Docker.PathExists(ctx, c.ID, sh); err == nil && ok {
					shell = sh
					break
				}
			}
		}
		front := newSSHFront(SSHOptions{User: string(a.spec.User), Password: pw, Keys: keys, HostKey: signer, Shell: shell, Root: root, Container: ctr}, a.ep)
		handle = front.handle
	case state.AccessDatabase:
		env, err := a.o.WorkloadEnv(a.spec.Workload)
		if err != nil {
			return fmt.Errorf("database credentials: %w", err)
		}
		hosts := make([]string, 0, len(a.spec.Hosts))
		for _, h := range a.spec.Hosts {
			hosts = append(hosts, string(h))
		}
		cert, ci, err := NewCertificate(hosts, deadline.Add(5*time.Minute))
		if err != nil {
			return err
		}
		a.status.Certificate = ci
		role := string(a.spec.User)
		if err := a.o.Manager.Remember(LedgerEntry{Access: a.spec.Access, Engine: a.spec.Engine, Workload: a.spec.Workload, Service: wl.Service, Environment: wl.Environment, Role: role, CreatedAt: a.o.Now().UTC()}); err != nil {
			return fmt.Errorf("access ledger: %w", err)
		}
		info, err := CreateRole(ctx, ctr, a.spec.Engine, env, role, pw, deadline.Add(5*time.Minute), a.spec.AllowWrites)
		defer a.dropRole(ctx, ctr, env, role)
		if err != nil {
			return fmt.Errorf("creating the temporary role: %w", err)
		}
		a.status.Database = state.TableName(info.Database)
		proxy := &dbProxy{ep: a.ep, o: DBOptions{
			Engine: a.spec.Engine, Role: role, Database: info.Database, TLS: cert, Dial: a.o.Dial,
			Upstream: func(ctx context.Context) (netip.AddrPort, error) { return a.upstream(ctx) },
		}}
		handle = proxy.handle
	}

	l, closeL, err := a.o.Manager.Open(ctx, a.spec.Access, allow)
	if err != nil {
		return err
	}
	defer closeL()
	a.status.Port = l.Addr().(*net.TCPAddr).Port //nolint:forcetypeassert // a TCP listener
	a.status.PrivateAddresses, a.status.BehindNat = a.o.Manager.Network()
	tasks.ReportAccess(ctx, a)
	tasks.HeartbeatNow(ctx)
	a.log.Info("access listening", "port", a.status.Port, "allow", len(allow))

	sctx, stop := context.WithCancel(ctx)
	served := make(chan struct{})
	go func() {
		defer close(served)
		a.ep.serve(sctx, l, handle)
	}()
	reason := a.loop(ctx)
	stop()
	closeL()
	<-served
	a.finish(reason)
	a.log.Info("access ended", "reason", reason)
	return nil
}

// loop waits for the end: idle, lockout, deadline, cancel or lost lease.
func (a *run) loop(ctx context.Context) state.AccessEndReason {
	idle := a.spec.IdleTimeout.Std()
	tick := time.NewTicker(a.o.StatusEvery)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			switch {
			case tasks.LeaseLost(ctx):
				return state.AccessEndError
			case errors.Is(ctx.Err(), context.DeadlineExceeded):
				return state.AccessEndDeadline
			default:
				return state.AccessEndEnded
			}
		case <-a.ep.locked:
			return state.AccessEndLocked
		case <-a.ep.changed:
			tasks.HeartbeatNow(ctx)
		case <-tick.C:
			if a.o.Now().Sub(a.ep.idleSince()) > idle {
				return state.AccessEndIdle
			}
			// A volume access ends when its workload leaves the desired
			// state (a restore or import holds the service, a move, a
			// removal): the helper must not keep the volume in use.
			if a.spec.Kind == state.AccessVolume {
				if _, ok := a.o.Workload(a.spec.Workload); !ok {
					a.log.Info("the workload left this server's desired state; ending the volume access")
					return state.AccessEndEnded
				}
			}
		}
	}
}

// HeartbeatStatus implements tasks.AccessReporter.
func (a *run) HeartbeatStatus() (*state.AccessStatus, func(bool)) {
	s := a.status
	active, _, _, _, _, _, last := a.ep.stats()
	s.ActiveConnections = active
	s.LastActivityAt = last.UTC()
	evs, dropped, done := a.ep.drainEvents()
	sort.SliceStable(evs, func(i, j int) bool { return evs[i].At.Before(evs[j].At) })
	s.Events, s.Dropped = evs, dropped
	return &s, done
}

func (a *run) finish(reason state.AccessEndReason) {
	_, conns, fails, in, out, started, _ := a.ep.stats()
	evs, _, done := a.ep.drainEvents()
	done(true)
	a.res.EndReason = reason
	a.res.Connections, a.res.AuthFailures, a.res.BytesIn, a.res.BytesOut, a.res.StartedAt = conns, fails, in, out, started
	a.res.Events = evs
}

// upstream is the database container's address on its environment network.
func (a *run) upstream(ctx context.Context) (netip.AddrPort, error) {
	c, _, err := a.target(ctx)
	if err != nil {
		return netip.AddrPort{}, err
	}
	port := uint16(5432)
	if a.spec.Engine != state.EnginePostgres {
		port = 6379
	}
	names := make([]string, 0, len(c.Networks))
	for n := range c.Networks {
		names = append(names, n)
	}
	sort.Strings(names)
	for _, n := range names {
		if ip := c.Networks[n]; ip.IsValid() && strings.HasPrefix(n, "lr-e_") {
			return netip.AddrPortFrom(ip, port), nil
		}
	}
	for _, n := range names {
		if ip := c.Networks[n]; ip.IsValid() {
			return netip.AddrPortFrom(ip, port), nil
		}
	}
	return netip.AddrPort{}, errors.New("the database container has no address")
}

// dropRole removes the role; on failure the ledger keeps it for the sweep.
func (a *run) dropRole(ctx context.Context, ctr Container, env map[string]secret.Secret, role string) {
	dctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	if err := DropRole(dctx, ctr, a.spec.Engine, env, role); err != nil {
		a.log.Warn("dropping the temporary role failed; it is retried later", "err", err)
		return
	}
	if err := a.o.Manager.Forget(role); err != nil {
		a.log.Warn("updating the access ledger failed", "err", err)
	}
}

// RandomName returns n characters of [a-z0-9] (for tests and tools).
func RandomName(n int) string {
	const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789"
	b := make([]byte, n)
	_, _ = rand.Read(b)
	for i := range b {
		b[i] = alphabet[int(b[i])%len(alphabet)]
	}
	return string(b)
}
