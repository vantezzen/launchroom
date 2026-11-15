package docker_test

import (
	"context"
	"errors"
	"testing"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const netServer = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")

var envNet = state.NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9B0", Name: "lr-e_a", Subnet: "10.231.5.0/24"}

// RemoveUnlessHeld and Hold never interleave: a hold taken while the
// removal runs waits for it, and a held network is never removed.
func TestNetworkHoldsSerialiseWithRemoval(t *testing.T) {
	ns := docker.NewNetworks(dockerfake.New(string(netServer)), netServer)
	started, proceed := make(chan struct{}), make(chan struct{})
	done := make(chan bool)
	go func() {
		removed, _ := ns.RemoveUnlessHeld("lr-e_a", func() error { close(started); <-proceed; return nil })
		done <- removed
	}()
	<-started
	held := make(chan struct{})
	go func() {
		if _, err := ns.Hold(context.Background(), envNet); err != nil {
			t.Error(err)
		}
		close(held)
	}()
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
	if removed, _ := ns.RemoveUnlessHeld("lr-e_a", func() error { t.Fatal("removed a held network"); return nil }); removed {
		t.Fatal("reported removal of a held network")
	}
}

func TestEnsureRefusesAnotherEnvironmentsNetwork(t *testing.T) {
	ns := docker.NewNetworks(dockerfake.New(string(netServer)), netServer)
	if err := ns.Ensure(t.Context(), envNet); err != nil {
		t.Fatal(err)
	}
	if err := ns.Ensure(t.Context(), envNet); err != nil {
		t.Fatalf("existing network: %v", err)
	}
	other := envNet
	other.ID = "net_01J9Z8X7W6V5T4S3R2Q1P0N9B1"
	if err := ns.Ensure(t.Context(), other); err == nil {
		t.Fatal("a network of another environment was accepted")
	}
}

// A container joins a launchroom network only once the isolation check
// passed; containers on the host network don't wait for it.
func TestIsolationGatesManagedNetworks(t *testing.T) {
	d := dockerfake.New(string(netServer))
	d.AddImage("img")
	ns := docker.NewNetworks(d, netServer)
	if err := ns.Ensure(t.Context(), envNet); err != nil {
		t.Fatal(err)
	}
	fail := errors.New("nftables unavailable")
	d.SetIsolation(func(context.Context) error { return fail })
	cc := func(name, network string) *docker.ContainerCreate {
		c := &docker.ContainerCreate{Name: name, Config: configWith(docker.ManagedLabels(string(netServer)))}
		c.HostConfig = hostConfig(network)
		return c
	}
	if _, err := d.CreateContainer(t.Context(), cc("lr-x", "lr-e_a")); !errors.Is(err, docker.ErrNotIsolated) || !errors.Is(err, fail) {
		t.Fatalf("err = %v", err)
	}
	if _, err := d.CreateContainer(t.Context(), cc("lr-system-caddy", "host")); err != nil {
		t.Fatalf("host network container: %v", err)
	}
}

func configWith(labels map[string]string) *container.Config {
	return &container.Config{Image: "img", Labels: labels}
}

func hostConfig(network string) *container.HostConfig {
	return &container.HostConfig{NetworkMode: container.NetworkMode(network)}
}
