package reconcile

import (
	"context"
	"net/netip"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// A workload published on the mesh waits for the mesh address: it is not
// created without it, an existing container is never touched while the
// address is unknown (a daemon restart must not recreate a database), and
// it is published on the mesh IP only once the address is known.
func TestPublishOnMeshWaitsForTheMeshAddress(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	var ip netip.Addr
	h.r.o.MeshIP = func() netip.Addr { return ip }
	w := h.workload(t, wlA, nil)
	w.PublishOnMesh = &state.MeshPublish{Port: 31001}
	ns := h.nodeState(t, 1, w)
	name := "lr-" + strings.ToLower(string(wlA))

	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if _, ok := h.d.Create(name); ok {
		t.Fatal("created before the mesh address was known")
	}
	// Pending, and saying why (D68: the rollout shows what it waits for).
	if st := phaseOf(h.r, wlA); st.Phase != state.PhasePending || st.Message != "waiting for this server's private network address" {
		t.Fatalf("status while waiting: %+v", st)
	}

	ip = netip.MustParseAddr("100.64.0.7")
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	cc, ok := h.d.Create(name)
	if !ok {
		t.Fatalf("not created: %q", h.d.Calls())
	}
	for port, bs := range cc.HostConfig.PortBindings {
		if port.Num() != 3000 || len(bs) != 1 || bs[0].HostIP != ip || bs[0].HostPort != "31001" {
			t.Fatalf("binding %v %+v", port, bs)
		}
	}
	if len(cc.HostConfig.PortBindings) != 1 {
		t.Fatalf("bindings %+v", cc.HostConfig.PortBindings)
	}

	// Restart: the address is unknown again for a moment. Nothing changes.
	h.d.Calls()
	ip = netip.Addr{}
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if calls := h.d.Calls(); slices.ContainsFunc(calls, func(c string) bool {
		return strings.HasPrefix(c, "remove") || strings.HasPrefix(c, "stop") || strings.HasPrefix(c, "create lr-")
	}) {
		t.Fatalf("touched the container without a mesh address: %q", calls)
	}
}

// Docker may start a published container while the mesh address is missing
// (at boot, by its restart policy) and leave the port unbound; the next pass
// with the address recreates it so the port is bound (a plain restart does
// not bind it in Docker 29).
func TestUnboundMeshPortIsRebound(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	ip := netip.MustParseAddr("100.64.0.7")
	h.r.o.MeshIP = func() netip.Addr { return ip }
	w := h.workload(t, wlA, nil)
	w.PublishOnMesh = &state.MeshPublish{Port: 31001}
	ns := h.nodeState(t, 1, w)
	name := "lr-" + strings.ToLower(string(wlA))
	h.d.Unbound = map[string]bool{name: true}
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	h.d.Calls()
	h.d.Unbound = nil
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	calls := h.d.Calls()
	if !slices.Contains(calls, "remove "+name) || !slices.Contains(calls, "create "+name) || calls[len(calls)-1] != "start "+name {
		t.Fatalf("calls %q", calls)
	}
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if calls := h.d.Calls(); len(calls) != 0 {
		t.Fatalf("bound container touched again: %q", calls)
	}
}

// System containers (the ambassador) are detached from an environment
// network before the reconciler removes it; Docker refuses otherwise.
func TestDetachBeforeNetworkRemove(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	var detached []string
	h.r.o.BeforeNetworkRemove = func(_ context.Context, network string) error {
		detached = append(detached, network)
		if slices.Contains(h.d.Calls(), "remove-network "+network) {
			t.Fatal("network removed before the detach hook")
		}
		return nil
	}
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, h.workload(t, wlA, nil))); err != nil {
		t.Fatal(err)
	}
	empty := h.nodeState(t, 2)
	empty.Networks = []state.NetworkSpec{}
	if err := h.r.Reconcile(ctx, empty); err != nil {
		t.Fatal(err)
	}
	if !slices.Equal(detached, []string{"lr-e_a"}) {
		t.Fatalf("detached %q", detached)
	}
}
