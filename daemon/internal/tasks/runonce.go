package tasks

import (
	"context"
	"crypto/ecdh"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// RunOnceOptions configures RunOnceHandler.
type RunOnceOptions struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Docker     docker.Client
	Client     Client
	Policy     policy.Config
	// Forget drops cached registry tokens of a finished task (optional).
	Forget func(state.TaskID)
	// PollEvery is how often the container state is checked (default 500 ms).
	PollEvery time.Duration
	// Networks ensures and holds the task's environment network, so the
	// reconciler does not remove it while the task uses it (shared with
	// reconcile.Options.Networks; nil: one of its own).
	Networks *docker.Networks
	// Probe checks a start-check target (spec.probe, docs/20 §5): nil error
	// when addr accepts TCP connections and, with a path, answers HTTP below
	// 500 (the status is returned). Default: DefaultStartProbe.
	Probe func(ctx context.Context, addr netip.AddrPort, path string) (int, error)
	// Ports finds the TCP ports a container listens on (nil = none; the
	// reconciler's scanner in production).
	Ports health.PortScanner
	// NodeState returns the verified NodeState (nil = none yet). spec.mounts
	// are refused without it.
	NodeState func() *state.NodeState
	// VolumeWait bounds the wait for spec.mounts' volumes (default
	// DefaultVolumeWait).
	VolumeWait time.Duration
}

// DefaultStartProbe dials addr from the host network namespace (images need
// no curl) and, with a path, sends one GET without proxies or redirects.
func DefaultStartProbe(ctx context.Context, addr netip.AddrPort, path string) (int, error) {
	dctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	var d net.Dialer
	c, err := d.DialContext(dctx, "tcp", addr.String())
	if err != nil {
		return 0, err
	}
	_ = c.Close()
	if path == "" {
		return 0, nil
	}
	hctx, hcancel := context.WithTimeout(ctx, 5*time.Second)
	defer hcancel()
	req, err := http.NewRequestWithContext(hctx, http.MethodGet, "http://"+addr.String()+path, http.NoBody)
	if err != nil {
		return 0, err
	}
	req.Header.Set("User-Agent", "launchroom-start-check/1")
	resp, err := startProbeClient.Do(req)
	if err != nil {
		return 0, err
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	_ = resp.Body.Close()
	if resp.StatusCode >= 500 {
		return resp.StatusCode, fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	return resp.StatusCode, nil
}

var startProbeClient = &http.Client{
	Transport: &http.Transport{
		Proxy:                  nil,
		DisableKeepAlives:      true,
		ResponseHeaderTimeout:  5 * time.Second,
		MaxResponseHeaderBytes: 64 << 10,
	},
	CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
}

// runOnceStopGrace is the SIGTERM grace period when a one-off container is
// cancelled or times out.
const runOnceStopGrace = 10 * time.Second

// RunOnceHandler returns the handler for "run_once" tasks (release and seed
// commands, docs/03 §7): a one-off container from the deployment image,
// attached to the environment network, with the sealed env, the argv from the
// spec and the policy floor applied exactly like a workload. Its logs ship as
// task logs (step "run"); it is removed when it exits, times out or is
// cancelled. The exit code is the result: a non-zero exit is still a
// successful task (the dashboard decides what it means). Shipped log lines
// are masked: the values of spec.maskEnv (raw, encoded, and the password of
// URL values such as DSNs) become "***" (docs/09 R16).
func RunOnceHandler(o RunOnceOptions) Handler {
	if o.PollEvery <= 0 {
		o.PollEvery = 500 * time.Millisecond
	}
	if o.Probe == nil {
		o.Probe = DefaultStartProbe
	}
	if o.Networks == nil {
		o.Networks = docker.NewNetworks(o.Docker, o.ServerID)
	}
	return func(ctx context.Context, t state.Task) (state.TaskResults, error) {
		spec, ok := t.Spec.(*state.RunOnceSpec)
		if !ok {
			return state.TaskResults{}, errors.New("run_once handler got a non-run_once spec")
		}
		if o.Forget != nil {
			defer o.Forget(t.ID)
		}
		env, err := sealed.OpenTaskEnv(spec.Env, o.SealingKey, o.ServerID, t.ID)
		if err != nil {
			return state.TaskResults{}, fmt.Errorf("run_once env: %w", err)
		}
		ctx, cancel := context.WithTimeout(ctx, spec.Timeout.Std())
		defer cancel()
		ctx = registry.WithTask(ctx, t.ID)

		logs := newStepShipper(ctx, o.Client, state.LogSource{Kind: state.LogSourceTask, ID: string(t.ID)}, t.Attempt)
		defer logs.Close()
		r := &oneOff{o: o, task: t.ID, logs: logs, mask: secret.NewMasker(secret.MaskValues(env, spec.MaskEnv)...)}

		// A retried attempt may find the container of an earlier one.
		if err := r.removeLeftovers(ctx); err != nil {
			return state.TaskResults{}, err
		}
		if err := policy.CheckNetworkName(spec.Network.Name); err != nil {
			return state.TaskResults{}, fmt.Errorf("run_once: %w", err)
		}
		// Held until the container is gone (deferred first, so released last).
		release, err := o.Networks.Hold(ctx, spec.Network)
		if err != nil {
			return state.TaskResults{}, fmt.Errorf("run_once: %w", err)
		}
		defer release()
		if err := r.ensureImage(ctx, spec.Image); err != nil {
			return state.TaskResults{}, err
		}
		mounts, vols, err := r.releaseMounts(ctx, spec)
		if err != nil {
			return state.TaskResults{}, err
		}
		cc, err := docker.BuildContainer(docker.WorkloadInput{
			ServerID: o.ServerID, Task: t.ID,
			Spec: state.WorkloadSpec{
				Service: spec.Service, Environment: spec.Environment, Deployment: spec.Deployment,
				Image: spec.Image, Command: spec.Command, Args: spec.Args, Env: spec.Env,
				Resources: spec.Resources, Networks: []state.NetworkAttachment{{Network: spec.Network.ID, Aliases: []state.Slug{}}},
				Restart: state.RestartNo, Role: state.RoleServing, Mounts: mounts,
			},
			Env:      env,
			Networks: map[state.NetworkID]state.HostName{spec.Network.ID: spec.Network.Name},
			Volumes:  vols,
		}, o.Policy)
		if err != nil {
			return state.TaskResults{}, fmt.Errorf("run_once container: %w", err)
		}

		started := time.Now()
		logs.Line("run", state.StreamSystem, "Starting one-off container") //nolint:contextcheck // logs ship on the shipper's own context
		id, err := o.Networks.CreateContainer(ctx, cc, []state.NetworkSpec{spec.Network})
		if err != nil {
			return state.TaskResults{}, fmt.Errorf("run_once create: %w", err)
		}
		defer r.remove(ctx, id)
		r.initOwners(ctx, mounts, vols)
		if err := o.Docker.StartContainer(ctx, id); err != nil {
			return state.TaskResults{}, fmt.Errorf("run_once start: %w", err)
		}
		if spec.Probe != nil {
			exit, outcome, err := r.probe(ctx, id, spec)
			if err != nil {
				return state.TaskResults{}, err
			}
			return state.TaskResults{RunOnce: &state.RunOnceResult{ExitCode: exit, DurationMs: time.Since(started).Milliseconds(), Probe: outcome}}, nil
		}
		exit, err := r.wait(ctx, id)
		if err != nil {
			return state.TaskResults{}, err
		}
		logs.Line("run", state.StreamSystem, fmt.Sprintf("Exited with code %d", exit)) //nolint:contextcheck // see above
		return state.TaskResults{RunOnce: &state.RunOnceResult{ExitCode: exit, DurationMs: time.Since(started).Milliseconds()}}, nil
	}
}

type oneOff struct {
	o    RunOnceOptions
	task state.TaskID
	logs *stepShipper
	mask *secret.Masker
}

func (r *oneOff) removeLeftovers(ctx context.Context) error {
	cs, err := r.o.Docker.ListContainers(ctx, map[string]string{names.LabelTask: string(r.task)})
	if err != nil {
		return fmt.Errorf("run_once: list containers: %w", err)
	}
	for _, c := range cs {
		if err := r.o.Docker.RemoveContainer(ctx, c.ID); err != nil && !errors.Is(err, docker.ErrNotFound) {
			return fmt.Errorf("run_once: remove leftover container: %w", err)
		}
	}
	return nil
}

func (r *oneOff) ensureImage(ctx context.Context, ref state.ImageRef) error {
	if err := policy.CheckImage(ref, r.o.Policy); err != nil {
		return err
	}
	ok, err := r.o.Docker.ImagePresent(ctx, string(ref))
	if err != nil {
		return fmt.Errorf("run_once: inspect image: %w", err)
	}
	if ok {
		return nil
	}
	r.logs.Line("run", state.StreamSystem, "Pulling the deployment image") //nolint:contextcheck // logs ship on the shipper's own context
	if err := r.o.Docker.PullImage(ctx, string(ref)); err != nil {
		return fmt.Errorf("pull failed: %w", err)
	}
	return nil
}

// probe is the start check (spec.probe, docs/20 §5): follow the logs, and
// until probe.within passes, see whether the container accepts connections
// on the port (and answers the path). A passing check stops the container
// and reports exit code 0; an exit or the deadline report what happened.
func (r *oneOff) probe(ctx context.Context, id string, spec *state.RunOnceSpec) (int, *state.ProbeOutcome, error) {
	p := spec.Probe
	stopLogs, logsDone := r.followLogs(ctx, id)
	defer stopLogs()
	start := time.Now()
	deadline := start.Add(p.Within.Std())
	port := int(p.Port)
	r.logs.Line("run", state.StreamSystem, fmt.Sprintf("Checking that the app listens on port %d", port)) //nolint:contextcheck // logs ship on the shipper's own context
	tick := time.NewTicker(r.o.PollEvery)
	defer tick.Stop()
	finish := func() {
		stopLogs()
		<-logsDone
	}
	stop := func() {
		sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), runOnceStopGrace+15*time.Second)
		_ = r.o.Docker.StopContainer(sctx, id, runOnceStopGrace)
		cancel()
	}
	for {
		select {
		case <-ctx.Done():
			stop()
			finish()
			return 0, nil, ctx.Err()
		case <-tick.C:
		}
		c, err := r.o.Docker.InspectContainer(ctx, id)
		if err != nil {
			if ctx.Err() != nil {
				continue
			}
			finish()
			return 0, nil, fmt.Errorf("run_once: inspect: %w", err)
		}
		elapsed := time.Since(start).Milliseconds()
		if !c.Running() && c.State != docker.StateCreated {
			select {
			case <-logsDone:
			case <-time.After(2 * time.Second):
			}
			finish()
			r.logs.Line("run", state.StreamSystem, fmt.Sprintf("Exited with code %d before listening on port %d", c.ExitCode, port)) //nolint:contextcheck // see above
			return c.ExitCode, &state.ProbeOutcome{Exited: true, AfterMs: elapsed}, nil
		}
		if ip, ok := c.Networks[string(spec.Network.Name)]; ok && ip.IsValid() && c.Running() {
			status, perr := r.o.Probe(ctx, netip.AddrPortFrom(ip, uint16(port)), string(p.Path)) //nolint:gosec // port validated 1-65535
			if perr == nil {
				r.logs.Line("run", state.StreamSystem, fmt.Sprintf("Port %d answered after %.1fs", port, float64(elapsed)/1000)) //nolint:contextcheck // see above
				stop()
				finish()
				return 0, &state.ProbeOutcome{Listening: true, AfterMs: elapsed, HTTPStatus: status}, nil
			}
		}
		if time.Now().After(deadline) {
			out := &state.ProbeOutcome{AfterMs: elapsed}
			var listening []int
			if r.o.Ports != nil && c.Pid > 0 {
				if ls, err := r.o.Ports.Listeners(c.Pid); err == nil {
					listening = health.Ports(ls)
				}
			}
			for _, lp := range listening {
				if lp > 0 && lp < 65536 && len(out.ListeningPorts) < 16 && !slices.Contains(out.ListeningPorts, state.Port(lp)) {
					out.ListeningPorts = append(out.ListeningPorts, state.Port(lp))
				}
			}
			r.logs.Line("run", state.StreamSystem, fmt.Sprintf("Nothing answered on port %d within %s", port, p.Within.Std())) //nolint:contextcheck // see above
			stop()
			finish()
			exit := 0
			if c2, err := r.o.Docker.InspectContainer(context.WithoutCancel(ctx), id); err == nil {
				exit = c2.ExitCode
			}
			return exit, out, nil
		}
	}
}

// followLogs ships the container's (masked) log lines in step "run" until
// stopped; done closes when the stream ended.
func (r *oneOff) followLogs(ctx context.Context, id string) (stop func(), done <-chan struct{}) {
	logCtx, cancel := context.WithCancel(context.WithoutCancel(ctx))
	logsDone := make(chan struct{})
	go func() {
		defer close(logsDone)
		//nolint:contextcheck // log lines ship on the shipper's own context
		_ = r.o.Docker.StreamLogs(logCtx, id, docker.LogsOptions{Tail: -1, Follow: true}, func(l docker.LogLine) error {
			stream := state.StreamStdout
			if l.Stream == "stderr" {
				stream = state.StreamStderr
			}
			r.logs.Line("run", stream, r.mask.Mask(l.Text))
			return nil
		})
	}()
	return cancel, logsDone
}

// wait follows the container's logs until it exits and returns its exit code.
// When ctx ends first (timeout, cancel), the container is stopped.
func (r *oneOff) wait(ctx context.Context, id string) (int, error) {
	logCtx, stopLogs := context.WithCancel(context.WithoutCancel(ctx))
	defer stopLogs()
	logsDone := make(chan struct{})
	go func() {
		defer close(logsDone)
		//nolint:contextcheck // log lines ship on the shipper's own context
		_ = r.o.Docker.StreamLogs(logCtx, id, docker.LogsOptions{Tail: -1, Follow: true}, func(l docker.LogLine) error {
			stream := state.StreamStdout
			if l.Stream == "stderr" {
				stream = state.StreamStderr
			}
			r.logs.Line("run", stream, r.mask.Mask(l.Text))
			return nil
		})
	}()
	tick := time.NewTicker(r.o.PollEvery)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), runOnceStopGrace+15*time.Second)
			_ = r.o.Docker.StopContainer(sctx, id, runOnceStopGrace)
			cancel()
			stopLogs()
			<-logsDone
			return 0, ctx.Err()
		case <-tick.C:
		}
		c, err := r.o.Docker.InspectContainer(ctx, id)
		if err != nil {
			if ctx.Err() != nil {
				continue
			}
			return 0, fmt.Errorf("run_once: inspect: %w", err)
		}
		if c.Running() || c.State == docker.StateCreated {
			continue
		}
		// Give the log stream a moment to deliver the last lines.
		select {
		case <-logsDone:
		case <-time.After(2 * time.Second):
		}
		stopLogs()
		<-logsDone
		return c.ExitCode, nil
	}
}

func (r *oneOff) remove(ctx context.Context, id string) {
	rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	_ = r.o.Docker.RemoveContainer(rctx, id)
}
