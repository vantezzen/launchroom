//go:build integration

package integration

import (
	"context"
	"log/slog"
	"net/http"
	"net/netip"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Rootless Docker (D49): `make test-daemon-integration-rootless` runs this
// binary like launchroomd on a rootless host, as a host-network container
// of a docker:dind-rootless daemon (so inside RootlessKit's network
// namespace), with dockerd-guard in front of the rootless socket and
// RootlessKit's API for the edge ports. LR_ITEST_OUTER_IP is the dind
// container's own address: a request to it on port 80 enters through
// RootlessKit's port forwarding, exactly like a visitor on a real server.

func rootlessOnly(t *testing.T) {
	t.Helper()
	if os.Getenv("LR_ITEST_ROOTLESS") != "1" {
		t.Skip("rootless Docker only (make test-daemon-integration-rootless)")
	}
}

func TestRootlessDeployAndRouting(t *testing.T) {
	rootlessOnly(t)
	e := setup(t)
	ctx := t.Context()

	facts, err := e.engine.Runtime(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if !facts.Rootless || !facts.Guard || !facts.EdgePorts {
		t.Fatalf("runtime facts %+v, want rootless through the guard with edge ports", facts)
	}
	ports, err := e.engine.EnsureEdgePorts(ctx)
	if err != nil {
		t.Fatal(err)
	}
	for _, p := range ports {
		if !p.Exposed {
			t.Fatalf("edge port %d/%s not exposed: %s", p.Port, p.Proto, p.Message)
		}
	}
	// Idempotent: a second call finds them.
	if again, err := e.engine.EnsureEdgePorts(ctx); err != nil || len(again) != 3 {
		t.Fatalf("second edge port call: %v %v", again, err)
	}

	ns, wl := e.nodeState(t, 1, true, true)
	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return status(e.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	st := status(e.rec, wl)
	t.Logf("workload on rootless Docker: %+v", st)
	t.Logf("measured: port discovery on rootless Docker found %v", st.DetectedPorts)

	// Inside the namespace (where launchroomd and Caddy live).
	if code, _, _, err := get(t, noRedirect, "http://127.0.0.1:80/", testHost); err != nil || code != http.StatusPermanentRedirect {
		t.Fatalf("inside: %d %v", code, err)
	}
	// From outside, through RootlessKit's port forwarding.
	outer := os.Getenv("LR_ITEST_OUTER_IP")
	if _, err := netip.ParseAddr(outer); err != nil {
		t.Fatalf("LR_ITEST_OUTER_IP %q", outer)
	}
	deadline := time.Now().Add(30 * time.Second)
	for {
		code, hdr, _, err := get(t, noRedirect, "http://"+outer+":80/x", testHost)
		if err == nil && code == http.StatusPermanentRedirect && hdr.Get("Location") == "https://"+testHost+"/x" {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("outside: %d %v %v", code, hdr, err)
		}
		time.Sleep(time.Second)
	}
	code, _, body, err := get(t, noRedirect, "http://"+outer+":80/", "unknown.localhost")
	if err != nil || code != http.StatusNotFound || !strings.Contains(body, "launchroom") {
		t.Fatalf("outside, unknown host: %d %q %v", code, body, err)
	}
}

// TestRootlessMeasurements records what works in RootlessKit's namespace
// (documented in docs/11 §3.2): nftables isolation with an environment
// network, and the mesh client in kernel (TUN) mode with launchroomd's own
// spec, created through the guard.
func TestRootlessMeasurements(t *testing.T) {
	rootlessOnly(t)
	e := setup(t)
	ctx, cancel := context.WithTimeout(t.Context(), 5*time.Minute)
	defer cancel()
	labels := docker.ManagedLabels(string(e.serverID))
	if _, err := e.engine.CreateNetwork(ctx, docker.NetworkCreate{Name: "lr-e_rootless", Subnet: netip.MustParsePrefix("10.231.251.0/24"), Labels: labels}); err != nil {
		t.Fatal(err)
	}
	fw := firewall.New(firewall.Options{
		Mode: firewall.ModeEnforce, Backend: firewall.NewBackend(), Networks: e.engine.ListNetworks,
		Log: slog.Default(), Now: time.Now,
	})
	err := fw.Sync(ctx)
	t.Logf("measured: nftables in the rootless namespace: sync err=%v status=%+v", err, fw.Status())
	_ = fw.Remove()

	if err := e.engine.PullImage(ctx, string(tailscaleImage)); err != nil {
		t.Fatal(err)
	}
	cc := mesh.ContainerSpec(e.serverID, tailscaleImage)
	id, err := e.engine.CreateContainer(ctx, cc)
	if err != nil {
		t.Fatalf("the guard refused launchroomd's mesh client spec: %v", err)
	}
	t.Cleanup(func() { _ = e.engine.RemoveContainer(context.WithoutCancel(ctx), id) })
	if err := e.engine.StartContainer(ctx, id); err != nil {
		t.Logf("measured: the mesh client does not start on rootless Docker: %v", err)
		return
	}
	time.Sleep(10 * time.Second)
	c, err := e.engine.InspectContainer(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	var lines []string
	_ = e.engine.StreamLogs(ctx, id, docker.LogsOptions{Tail: 200}, func(l docker.LogLine) error {
		if strings.Contains(strings.ToLower(l.Text), "tun") {
			lines = append(lines, l.Text)
		}
		return nil
	})
	t.Logf("measured: mesh client (kernel/TUN mode) on rootless Docker: running=%v restarts=%d tun log lines=%q", c.Running, c.RestartCount, lines)
}
