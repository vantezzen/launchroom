package reconcile

import (
	"context"
	"fmt"
	"net/netip"
	"slices"
	"strings"
	"testing"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func createManagedNet(t *testing.T, d *dockerfake.Fake, n state.NetworkSpec) {
	t.Helper()
	labels := docker.ManagedLabels(srvID)
	labels[docker.LabelNetwork] = string(n.ID)
	if _, err := d.CreateNetwork(t.Context(), docker.NetworkCreate{Name: string(n.Name), Subnet: netip.MustParsePrefix(string(n.Subnet)), Labels: labels}); err != nil {
		t.Fatal(err)
	}
}

func hasNetwork(t *testing.T, d *dockerfake.Fake, name string) bool {
	t.Helper()
	nets, err := d.ListNetworks(t.Context())
	if err != nil {
		t.Fatal(err)
	}
	return slices.ContainsFunc(nets, func(n docker.Network) bool { return n.Name == name })
}

// The previews race: a run_once task creates an environment network this
// server has no workloads in yet; a reconcile pass must not remove it before
// the task attached its container.
func TestHeldNetworkIsNotRemoved(t *testing.T) {
	h := newHarness(t)
	var holds docker.NetworkHolds
	h.r.o.Holds = &holds
	other := state.NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9B0", Name: "lr-e_b", Subnet: "10.231.5.0/24"}
	createManagedNet(t, h.d, other)

	release := holds.Hold("lr-e_b")
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 1)); err != nil {
		t.Fatal(err)
	}
	if !hasNetwork(t, h.d, "lr-e_b") {
		t.Fatal("held network was removed")
	}
	release()
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 2)); err != nil {
		t.Fatal(err)
	}
	if hasNetwork(t, h.d, "lr-e_b") {
		t.Fatal("released, undesired network was kept")
	}
}

// RemoveUnlessHeld and Hold never interleave: a hold taken while the
// removal runs waits for it, and a held network is never removed.
func TestNetworkHoldsSerialiseWithRemoval(t *testing.T) {
	var holds docker.NetworkHolds
	started, proceed := make(chan struct{}), make(chan struct{})
	done := make(chan bool)
	go func() {
		removed, _ := holds.RemoveUnlessHeld("lr-e_a", func() error { close(started); <-proceed; return nil })
		done <- removed
	}()
	<-started
	held := make(chan struct{})
	go func() { holds.Hold("lr-e_a"); close(held) }()
	select {
	case <-held:
		t.Fatal("hold acquired while the removal was running")
	default:
	}
	close(proceed)
	if !<-done {
		t.Fatal("not removed")
	}
	<-held
	if removed, _ := holds.RemoveUnlessHeld("lr-e_a", func() error { t.Fatal("removed a held network"); return nil }); removed {
		t.Fatal("reported removal of a held network")
	}
}

// vanishingNetwork removes the network right before the first container
// create, like a concurrent removal or Docker not seeing it yet.
type vanishingNetwork struct {
	*dockerfake.Fake
	once bool
}

func (v *vanishingNetwork) CreateContainer(ctx context.Context, cc *docker.ContainerCreate) (string, error) {
	if !v.once {
		v.once = true
		v.RemoveNetworkByName("lr-e_a")
	}
	return v.Fake.CreateContainer(ctx, cc)
}

func TestCreateRetriesWhenNetworkVanishes(t *testing.T) {
	h := newHarness(t)
	h.r.o.Docker = &vanishingNetwork{Fake: h.d}
	w := h.workload(t, wlA, nil)
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 1, w)); err != nil {
		t.Fatal(err)
	}
	calls := h.d.Calls()
	if n := slices.Index(calls, "create-network lr-e_a 10.231.4.0/24"); n < 0 || !slices.Contains(calls[n+1:], "create-network lr-e_a 10.231.4.0/24") {
		t.Fatalf("network not re-created: %q", calls)
	}
	if !slices.Contains(calls, "start "+docker.ContainerName(wlA)) {
		t.Fatalf("workload not started: %q", calls)
	}
	if ph := phaseOf(h.r, wlA).Phase; ph == state.PhaseFailed {
		t.Fatalf("phase %s", ph)
	}
}

// racingCreate: another creator (a run_once task) wins the network creation.
type racingCreate struct{ *dockerfake.Fake }

func (r racingCreate) CreateNetwork(ctx context.Context, n docker.NetworkCreate) (string, error) {
	if _, err := r.Fake.CreateNetwork(ctx, n); err != nil {
		return "", err
	}
	return "", fmt.Errorf("conflict: network %s already exists", n.Name)
}

func TestConcurrentNetworkCreationIsNotAFailure(t *testing.T) {
	h := newHarness(t)
	h.r.o.Docker = racingCreate{h.d}
	w := h.workload(t, wlA, nil)
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 1, w)); err != nil {
		t.Fatalf("conflict on an existing, matching network failed the pass: %v", err)
	}
	if !slices.Contains(h.d.Calls(), "start "+docker.ContainerName(wlA)) {
		t.Fatalf("calls %q", h.d.Calls())
	}
}

func volLabels(id state.VolumeID, svc state.ServiceID, env state.EnvironmentID) map[string]string {
	l := docker.ManagedLabels(srvID)
	l[docker.LabelVolume] = string(id)
	l[docker.LabelService] = string(svc)
	l[docker.LabelEnvironment] = string(env)
	return l
}

func TestDeleteVolumes(t *testing.T) {
	h := newHarness(t)
	ctx := t.Context()
	const (
		volC = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9C1")
		volD = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9D1")
		volE = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9E2")
	)
	h.d.AddVolume("lr-v_a", volLabels(volA, svcA, envA))    // deletable
	h.d.AddVolume("lr-v_b", volLabels(volB, svcB, envA))    // labels say another service
	h.d.AddVolume("lr-v_c", volLabels(volC, svcA, envA))    // in use
	h.d.AddUnmanagedVolume("lr-v_d")                        // not ours: never touched
	h.d.AddVolume("lr-v_keep", volLabels(volE, svcA, envA)) // not requested: stays although undeclared
	h.d.AddImage(string(imgA))
	labels := docker.ManagedLabels(srvID)
	labels[docker.LabelTask] = "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Z9" // a one-off container: the reconciler leaves it alone
	if _, err := h.d.CreateContainer(ctx, &docker.ContainerCreate{Name: "lr-user", Config: &container.Config{Image: string(imgA), Labels: labels},
		HostConfig: &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: "lr-v_c", Target: "/data"}}}}); err != nil {
		t.Fatal(err)
	}

	ns := h.nodeState(t, 1)
	req := func(id state.VolumeID, name string) state.VolumeDeletion {
		return state.VolumeDeletion{ID: id, Name: state.HostName(name), Service: svcA, Environment: envA, RequestedAt: t0}
	}
	ns.DeleteVolumes = []state.VolumeDeletion{req(volA, "lr-v_a"), req(volB, "lr-v_b"), req(volC, "lr-v_c"), req(volD, "lr-v_d")}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	got := map[state.VolumeID]state.VolumeDeletionResult{}
	for _, r := range h.r.VolumeDeletions() {
		got[r.ID] = r
	}
	if got[volA].Outcome != state.VolumeDeleted || got[volB].Outcome != state.VolumeRefused || got[volC].Outcome != state.VolumeRefused || got[volD].Outcome != state.VolumeAbsent {
		t.Fatalf("outcomes %+v", got)
	}
	if !strings.Contains(got[volC].Message, "in use by lr-user") || !strings.Contains(got[volB].Message, "labels do not match") {
		t.Fatalf("messages %+v", got)
	}
	vols, _ := h.d.ListVolumes(ctx)
	names := []string{}
	for _, v := range vols {
		names = append(names, v.Name)
	}
	if !slices.Equal(names, []string{"lr-v_b", "lr-v_c", "lr-v_keep"}) {
		t.Fatalf("volumes left %v", names)
	}
	removals := slices.DeleteFunc(h.d.Calls(), func(c string) bool { return !strings.HasPrefix(c, "remove-volume") })
	if !slices.Equal(removals, []string{"remove-volume lr-v_a"}) {
		t.Fatalf("removals %q", removals)
	}

	// The user goes away: the refused deletion is retried and succeeds;
	// final outcomes are not repeated.
	cs, _ := h.d.ListContainers(ctx, nil)
	for _, c := range cs {
		_ = h.d.RemoveContainer(ctx, c.ID)
	}
	ns2 := *ns
	ns2.Version = 2
	if err := h.r.Reconcile(ctx, &ns2); err != nil {
		t.Fatal(err)
	}
	removals = slices.DeleteFunc(h.d.Calls(), func(c string) bool { return !strings.HasPrefix(c, "remove-volume") })
	if !slices.Equal(removals, []string{"remove-volume lr-v_c"}) {
		t.Fatalf("second pass removals %q", removals)
	}

	// A state without the request forgets the outcomes; undeclared volumes stay.
	if err := h.r.Reconcile(ctx, h.nodeState(t, 3)); err != nil {
		t.Fatal(err)
	}
	if len(h.r.VolumeDeletions()) != 0 {
		t.Fatal("outcomes kept after the request left the state")
	}
	if vols, _ := h.d.ListVolumes(ctx); len(vols) != 2 {
		t.Fatalf("volumes %v", vols)
	}
}
