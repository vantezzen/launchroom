package session

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Recovery sessions (D92, docs/03 §11): a console in "lr-rec-<session>", a
// container launchroomd creates from the workload's current spec (image,
// sealed env, user, working dir, networks, volumes and config files) with
// an idle process instead of the app: for a container that never stays up,
// so its files, volumes and binaries can be looked at. No probe, no route,
// no network alias, no restart; volumes are read-only unless the dashboard
// sends allowChanges (audited there). The idle process is a tiny static
// program launchroomd copies in through the archive route (no shell string,
// nothing from the image runs). The container is removed when the session
// ends; leftovers of an earlier launchroomd run are removed before the next
// recovery session starts (and by the host cleanup).

// RecoveryPrefix starts the names of recovery containers.
const RecoveryPrefix = names.Prefix + "rec-"

// RecoveryContainer is the name of a session's recovery container.
func RecoveryContainer(id state.SessionID) string { return RecoveryPrefix + state.LowerID(id) }

// idlePath is where the idle program is copied in the recovery container.
const idlePath = "/.launchroom-idle"

// Recovery is what recovery sessions need on top of Options: the verified
// desired state (the workload's spec, its networks and volumes) and the
// local policy floor the container is built with.
type Recovery struct {
	NodeState func() *state.NodeState
	Policy    policy.Config
}

// RecoveryDocker is what recovery sessions need from the Engine on top of
// Docker: copying the idle program in.
type RecoveryDocker interface {
	StreamToContainer(ctx context.Context, id, dst string, tar io.Reader) error
}

// FeaturesFor are the session kinds this server offers, recovery included
// when it is configured and allowed.
func FeaturesFor(o Options) []state.DaemonFeature {
	allowed := o.Allowed
	if allowed == nil {
		allowed = func(state.SessionKind) bool { return true }
	}
	out := Features(allowed)
	if _, ok := o.Docker.(RecoveryDocker); ok && o.Recovery != nil && o.Recovery.NodeState != nil && allowed(state.SessionRecovery) {
		out = append(out, state.FeatureRecoverySessions)
	}
	return out
}

// activeRecovery are the recovery containers of the sessions running in this
// process (by name): any other lr-rec- container is a leftover.
var activeRecovery = struct {
	sync.Mutex
	names map[string]bool
}{names: map[string]bool{}}

// prepareRecovery creates and starts the recovery container (s.container).
func (s *run) prepareRecovery(ctx context.Context) error {
	rec := s.o.Recovery
	cp, ok := s.o.Docker.(RecoveryDocker)
	if rec == nil || rec.NodeState == nil || !ok {
		return &state.StartError{Code: state.FailKindDisabled, Err: errors.New("recovery sessions are not available on this server")}
	}
	wl := s.spec.Workload
	ns := rec.NodeState()
	var spec *state.WorkloadSpec
	if ns != nil {
		spec = ns.Workload(wl)
	}
	if spec == nil {
		return fmt.Errorf("%w: workload %s is not in this server's desired state", ErrRefused, wl)
	}
	// A service that is up and serving keeps its volumes to itself: a
	// writable copy next to it could corrupt them (databases above all).
	if c, err := docker.WorkloadContainer(ctx, s.o.Docker, wl); err == nil && c != nil && s.spec.AllowChanges && len(spec.Mounts) > 0 &&
		c.Running() && !c.Restarting() {
		return fmt.Errorf("%w: the service is running; stop it before opening a recovery shell that can change its volumes", ErrRefused)
	}
	env, err := s.o.WorkloadEnv(wl)
	if err != nil {
		return fmt.Errorf("variables: %w", err)
	}
	nets := make(map[state.NetworkID]state.HostName, len(ns.Networks))
	for _, n := range ns.Networks {
		nets[n.ID] = n.Name
	}
	vols := make(map[state.VolumeID]state.HostName, len(ns.Volumes))
	for _, v := range ns.Volumes {
		vols[v.ID] = v.Name
	}
	cc, err := docker.BuildContainer(docker.WorkloadInput{ServerID: s.o.ServerID, Spec: recoverySpec(spec, s.spec.AllowChanges), Env: env, Networks: nets, Volumes: vols}, rec.Policy)
	if err != nil {
		return fmt.Errorf("%w: %w", ErrRefused, err)
	}
	name := RecoveryContainer(s.spec.Session)
	cc.Name = name
	delete(cc.Config.Labels, names.LabelWorkload) // never mistaken for the workload
	cc.Config.Labels[names.LabelTask] = string(s.t.ID)
	cc.Config.Labels[names.LabelWorkload+".recovery"] = string(wl)

	activeRecovery.Lock()
	activeRecovery.names[name] = true
	activeRecovery.Unlock()
	s.recovery = name
	s.sweepRecovery(ctx)

	prog, err := idleProgram(s.idleArch(ctx, spec.Image))
	if err != nil {
		return &state.StartError{Code: state.FailPolicyRefused, Err: err}
	}
	id, err := s.o.Docker.CreateContainer(ctx, cc)
	if err != nil {
		return fmt.Errorf("creating the recovery container: %w", err)
	}
	s.container = id
	if err := cp.StreamToContainer(ctx, id, "/", bytes.NewReader(prog)); err != nil {
		return fmt.Errorf("preparing the recovery container: %w", err)
	}
	if err := s.o.Docker.StartContainer(ctx, id); err != nil {
		return fmt.Errorf("starting the recovery container: %w", err)
	}
	deadline := time.Now().Add(10 * time.Second)
	for {
		c, err := s.o.Docker.InspectContainer(ctx, id)
		switch {
		case err != nil:
			return err
		case c.Running() && !c.Restarting():
			s.log.Info("recovery container started", "container", name, "writable", s.spec.AllowChanges)
			return nil
		case c.State == docker.StateExited || c.State == docker.StateDead || time.Now().After(deadline):
			return &state.StartError{Code: state.FailNotRunning, Err: fmt.Errorf("the recovery container didn't stay up (%s, exit code %d)", c.State, c.ExitCode)}
		}
		if !sleep(ctx, 100*time.Millisecond) {
			return ctx.Err()
		}
	}
}

// recoverySpec is the workload's spec as a recovery container: the idle
// program instead of the app, nothing that serves or restarts it.
func recoverySpec(w *state.WorkloadSpec, writable bool) state.WorkloadSpec {
	rs := *w
	rs.Command, rs.Args = []string{idlePath}, nil
	rs.Port, rs.Health, rs.PublishOnMesh, rs.Schedule = nil, nil, nil, nil
	rs.Restart = state.RestartNo
	rs.Networks = make([]state.NetworkAttachment, len(w.Networks))
	for i, n := range w.Networks {
		rs.Networks[i] = state.NetworkAttachment{Network: n.Network} // no aliases: it never receives traffic
	}
	rs.Mounts = make([]state.Mount, len(w.Mounts))
	for i, m := range w.Mounts {
		rs.Mounts[i] = state.Mount{Volume: m.Volume, Target: m.Target, ReadOnly: m.ReadOnly || !writable}
	}
	if w.Stop != nil {
		st := *w.Stop
		st.NoInit = false // the idle program is never PID 1
		rs.Stop = &st
	}
	return rs
}

// idleArch is the architecture of the idle program: the image's (it may run
// emulated), else the server's.
func (s *run) idleArch(ctx context.Context, image state.ImageRef) string {
	if ops, ok := s.o.Docker.(docker.ImageOps); ok {
		if cfg, err := ops.ImageConfig(ctx, string(image)); err == nil && cfg.Architecture != "" {
			return docker.NormalizeArch(cfg.Architecture)
		}
	}
	if info, err := s.o.Docker.Info(ctx); err == nil {
		return docker.NormalizeArch(info.Architecture)
	}
	return ""
}

// sweepRecovery removes recovery containers no running session owns.
func (s *run) sweepRecovery(ctx context.Context) {
	cs, err := s.o.Docker.ListContainers(ctx, nil)
	if err != nil {
		return
	}
	activeRecovery.Lock()
	var stale []docker.Container
	for _, c := range cs {
		if strings.HasPrefix(c.Name, RecoveryPrefix) && !activeRecovery.names[c.Name] {
			stale = append(stale, c)
		}
	}
	activeRecovery.Unlock()
	for _, c := range stale {
		s.log.Info("removing a leftover recovery container", "container", c.Name)
		_ = s.o.Docker.RemoveContainer(ctx, c.ID)
	}
}

// endRecovery removes the session's recovery container.
func (s *run) endRecovery() {
	if s.recovery == "" {
		return
	}
	if s.container != "" {
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		if err := s.o.Docker.RemoveContainer(ctx, s.container); err != nil && !errors.Is(err, docker.ErrNotFound) {
			s.logger().Warn("removing the recovery container failed", "err", err)
		}
	}
	activeRecovery.Lock()
	delete(activeRecovery.names, s.recovery)
	activeRecovery.Unlock()
}

func sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

// ---- The idle program ----
//
// A static ELF executable of a few instructions that blocks in a syscall
// forever (amd64: pause; arm64: ppoll without descriptors) and ends with the
// default action of the stop signal. Built here byte by byte so launchroomd
// ships no binary blob and needs nothing from the image.

// idleCode is the machine code per architecture.
var idleCode = map[string]struct {
	machine uint16
	code    []byte
}{
	// loop: mov eax, 34 (pause); syscall; jmp loop
	"amd64": {machine: 62, code: []byte{0xb8, 0x22, 0x00, 0x00, 0x00, 0x0f, 0x05, 0xeb, 0xf7}},
	// loop: mov x0..x3, #0; mov x8, #73 (ppoll); svc #0; b loop
	"arm64": {machine: 183, code: le32(0xd2800000, 0xd2800001, 0xd2800002, 0xd2800003, 0xd2800928, 0xd4000001, 0x17fffffa)},
}

func le32(words ...uint32) []byte {
	b := make([]byte, 0, 4*len(words))
	for _, w := range words {
		b = binary.LittleEndian.AppendUint32(b, w)
	}
	return b
}

// IdleELF returns the idle program for arch ("amd64", "arm64").
func IdleELF(arch string) ([]byte, error) {
	c, ok := idleCode[arch]
	if !ok {
		return nil, fmt.Errorf("recovery shells are not available for %q servers (amd64 and arm64 only)", arch)
	}
	const (
		base   = 0x400000
		ehSize = 64
		phSize = 56
	)
	entry := uint64(base + ehSize + phSize)
	total := uint64(ehSize + phSize + len(c.code))
	var b bytes.Buffer
	le := binary.LittleEndian
	w := func(v any) { _ = binary.Write(&b, le, v) }
	b.Write([]byte{0x7f, 'E', 'L', 'F', 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0})
	w(uint16(2))       // e_type: executable
	w(c.machine)       // e_machine
	w(uint32(1))       // e_version
	w(entry)           // e_entry
	w(uint64(ehSize))  // e_phoff
	w(uint64(0))       // e_shoff
	w(uint32(0))       // e_flags
	w(uint16(ehSize))  // e_ehsize
	w(uint16(phSize))  // e_phentsize
	w(uint16(1))       // e_phnum
	w(uint16(64))      // e_shentsize
	w(uint16(0))       // e_shnum
	w(uint16(0))       // e_shstrndx
	w(uint32(1))       // p_type: PT_LOAD
	w(uint32(5))       // p_flags: R+X
	w(uint64(0))       // p_offset
	w(uint64(base))    // p_vaddr
	w(uint64(base))    // p_paddr
	w(total)           // p_filesz
	w(total)           // p_memsz
	w(uint64(0x10000)) // p_align
	b.Write(c.code)
	return b.Bytes(), nil
}

// idleProgram is the tar archive that puts the idle program at idlePath.
func idleProgram(arch string) ([]byte, error) {
	elf, err := IdleELF(arch)
	if err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	if err := tw.WriteHeader(&tar.Header{
		Typeflag: tar.TypeReg, Name: strings.TrimPrefix(idlePath, "/"), Mode: 0o555, Size: int64(len(elf)),
		ModTime: time.Unix(0, 0).UTC(), Format: tar.FormatPAX,
	}); err != nil {
		return nil, err
	}
	if _, err := tw.Write(elf); err != nil {
		return nil, err
	}
	if err := tw.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
