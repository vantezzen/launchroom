package backup

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"io"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/api/types/network"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// RuntimeDocker is what replays (point-in-time restores) and verifications
// need from the Docker layer: they run the engine's own image in a
// throwaway container next to the data.
type RuntimeDocker interface {
	docker.Client
	docker.Execer
	docker.VolumeOps
}

// Helper purposes (docker.LabelHelper) of throwaway containers and volumes.
const (
	helperReplay = "replay"
	helperVerify = "verify"
)

// engineCapabilities are what the database images' entrypoints need beyond
// cap-drop ALL (chown the data dir, drop to the engine's user) — the same
// set the dashboard's engine drivers grant their workloads.
var engineCapabilities = []string{"CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE", "FOWNER"}

// throwaway describes one engine container started for a replay or a
// verification: the engine image, one named volume, no network.
type throwaway struct {
	purpose   string
	image     state.ImageRef
	volume    string
	mountPath state.ContainerPath
	env       []string
	cmd       []string
	resources state.Resources
	// stopSignal is the engine's graceful-shutdown signal (default SIGTERM;
	// Postgres: SIGINT, its fast shutdown).
	stopSignal state.StopSignal
	// entrypoint replaces the image's (imports: sleep).
	entrypoint []string
	// network attaches the container to this managed environment network
	// ("" = no network at all).
	network string
	// noCaps adds no capability (imports run only client tools).
	noCaps bool
}

// throwawayStopTimeout is how long a throwaway engine may take to shut down.
const throwawayStopTimeout = 2 * time.Minute

func randomSuffix() string {
	b := make([]byte, 6)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// checkRuntime applies the policy floor to an engine image and its limits
// (docs/03 §8): digest-pinned from an allowed registry, present locally (it
// is the image the database runs; nothing is pulled), limits within the
// local maximums.
func (e *Engine) checkRuntime(ctx context.Context, image state.ImageRef, res state.Resources) error {
	if e.o.Runtime == nil {
		return fmt.Errorf("backup: running database images for restores: %w", ErrUnsupported)
	}
	if err := policy.Check(state.WorkloadSpec{Image: image, Resources: res}, e.o.Policy); err != nil {
		return err
	}
	ok, err := e.o.Runtime.ImagePresent(ctx, string(image))
	if err != nil {
		return err
	}
	if !ok {
		return fmt.Errorf("backup: the database image %s is not on this server", image.Repository())
	}
	return nil
}

// createThrowaway creates (does not start) the container. It is hardened
// like a workload (cap-drop ALL, no-new-privileges, log limits, resource
// limits) and has no network at all: replays and checks talk to the engine
// over its loopback/socket through exec. Like database workloads it runs
// without an init (D42): the image's entrypoint execs the engine as PID 1,
// so Docker's stop signal reaches it and it shuts down cleanly.
func (e *Engine) createThrowaway(ctx context.Context, t throwaway) (string, error) {
	cc, err := throwawayContainer(e.o.ServerID, t, e.o.Policy)
	if err != nil {
		return "", err
	}
	return e.o.Runtime.CreateContainer(ctx, cc)
}

// ImportContainer is the create request of an import's throwaway (exported
// for dockerd-guard's shape tests): the database image, entrypoint sleep,
// the environment network, no capabilities.
func ImportContainer(serverID string, image state.ImageRef, network string, res state.Resources, seconds int, pol policy.Config) (*docker.ContainerCreate, error) {
	return throwawayContainer(serverID, throwaway{
		purpose: helperImport, image: image, resources: res, network: network, noCaps: true,
		entrypoint: []string{"sleep"}, cmd: []string{strconv.Itoa(seconds)},
	}, pol)
}

func throwawayContainer(serverID string, t throwaway, pol policy.Config) (*docker.ContainerCreate, error) {
	labels := docker.ManagedLabels(serverID)
	labels[docker.LabelHelper] = t.purpose
	memory := int64(t.resources.MemoryMB) * 1024 * 1024
	pids := int64(t.resources.Pids)
	initProcess := false
	signal := t.stopSignal
	if signal == "" {
		signal = state.SignalTerm
	}
	stopTimeout := int(throwawayStopTimeout / time.Second)
	cfg := &container.Config{
		Image: string(t.image), Cmd: slices.Clone(t.cmd), Env: slices.Clone(t.env), Labels: labels, NetworkDisabled: t.network == "",
		StopSignal: string(signal), StopTimeout: &stopTimeout, Entrypoint: slices.Clone(t.entrypoint),
	}
	networkMode := container.NetworkMode("none")
	var networking *network.NetworkingConfig
	if t.network != "" {
		networkMode = container.NetworkMode(t.network)
		networking = &network.NetworkingConfig{EndpointsConfig: map[string]*network.EndpointSettings{t.network: {}}}
	}
	caps := slices.Clone(engineCapabilities)
	if t.noCaps {
		caps = nil
	}
	var mounts []mount.Mount
	if t.volume != "" {
		mounts = []mount.Mount{{Type: mount.TypeVolume, Source: t.volume, Target: string(t.mountPath)}}
	}
	hc := &container.HostConfig{
		NetworkMode: networkMode,
		LogConfig:   container.LogConfig{Type: policy.LogDriver, Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile}},
		CapDrop:     []string{"ALL"},
		CapAdd:      caps,
		SecurityOpt: []string{policy.NoNewPrivileges},
		Init:        &initProcess,
		Resources: container.Resources{
			NanoCPUs: int64(t.resources.CPUs * 1e9), Memory: memory, MemorySwap: memory, PidsLimit: &pids,
			// Below workloads (default 1024) when the CPU is contended.
			CPUShares: 256,
		},
		Mounts: mounts,
	}
	if err := policy.CheckContainer(cfg, hc, pol); err != nil {
		return nil, fmt.Errorf("backup: throwaway container violates the policy floor (bug): %w", err)
	}
	return &docker.ContainerCreate{Name: "lr-" + t.purpose + "-" + randomSuffix(), Config: cfg, HostConfig: hc, Networking: networking}, nil
}

// sweepThrowaways removes containers (and, for verifications, volumes) of
// an earlier replay or verification that was interrupted. Heavy backup work
// runs one at a time per server, so none is in use.
func (e *Engine) sweepThrowaways(ctx context.Context, purpose string) {
	cs, err := e.o.Runtime.ListContainers(ctx, map[string]string{docker.LabelHelper: purpose})
	if err == nil {
		for _, c := range cs {
			_ = e.o.Runtime.RemoveContainer(ctx, c.ID)
		}
	}
	if purpose != helperVerify {
		return
	}
	vols, err := e.o.Runtime.ListVolumes(ctx)
	if err != nil {
		return
	}
	for _, v := range vols {
		if v.Labels[docker.LabelHelper] == helperVerify {
			_ = e.o.Runtime.RemoveVolume(ctx, v.Name)
		}
	}
}

// removeQuietly removes a throwaway container after use.
func (e *Engine) removeQuietly(ctx context.Context, id string) {
	rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	_ = e.o.Runtime.RemoveContainer(rctx, id)
}

// execIn runs argv in a throwaway container and returns its stdout.
func (e *Engine) execIn(ctx context.Context, id string, stdin io.Reader, env []string, argv ...string) (string, docker.ExecResult, error) {
	var out strings.Builder
	res, err := e.o.Runtime.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Env: env, Stdin: stdin, Stdout: &limitedWriter{w: &out, n: 64 << 10}})
	return strings.TrimSpace(out.String()), res, err
}

// lastLogLines returns the tail of a container's log (secret-free: the
// engines log no credentials) for error messages.
func (e *Engine) lastLogLines(ctx context.Context, id string, n int) string {
	return firstLines(e.logTail(ctx, id, n))
}

// logTail returns the last n log lines of a container.
func (e *Engine) logTail(ctx context.Context, id string, n int) string {
	lctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
	defer cancel()
	var lines []string
	_ = e.o.Runtime.StreamLogs(lctx, id, docker.LogsOptions{Tail: n}, func(l docker.LogLine) error {
		lines = append(lines, strings.TrimSpace(l.Text))
		return nil
	})
	return strings.Join(lines, "\n")
}

// running reports whether the container still runs; when it stopped, the
// error carries its exit code and last log lines.
func (e *Engine) running(ctx context.Context, id, what string) error {
	c, err := e.o.Runtime.InspectContainer(ctx, id)
	if err != nil {
		return err
	}
	if c.Running || c.State == "created" || c.Restarting {
		return nil
	}
	return fmt.Errorf("backup: %s stopped (exit code %d): %s", what, c.ExitCode, e.lastLogLines(ctx, id, 20))
}

// stopThrowaway shuts the engine down cleanly with its stop signal
// (Postgres' fast shutdown writes a checkpoint) and fails unless it exited
// with 0. The caller removes the container.
func (e *Engine) stopThrowaway(ctx context.Context, id string) error {
	sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), throwawayStopTimeout+time.Minute)
	defer cancel()
	if err := e.o.Runtime.StopContainer(sctx, id, throwawayStopTimeout); err != nil {
		return fmt.Errorf("backup: stopping the database: %w", err)
	}
	c, err := e.o.Runtime.InspectContainer(sctx, id)
	if err != nil {
		return err
	}
	if c.ExitCode != 0 {
		return fmt.Errorf("backup: the database exited with code %d while shutting down: %s", c.ExitCode, e.lastLogLines(sctx, id, 5))
	}
	return nil
}

// limitedWriter keeps at most n bytes.
type limitedWriter struct {
	w io.Writer
	n int
}

func (l *limitedWriter) Write(p []byte) (int, error) {
	if l.n <= 0 {
		return len(p), nil
	}
	k := min(len(p), l.n)
	if _, err := l.w.Write(p[:k]); err != nil {
		return 0, err
	}
	l.n -= k
	return len(p), nil
}
