package docker

import (
	"context"
	"errors"
	"fmt"
	"net/netip"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Networks is the one creator and remover of environment networks inside a
// launchroomd. The reconciler and run_once tasks share it: a task may need
// an environment network this server has no workloads in yet (a release
// command before the first rollout), so it holds the network while it runs
// and the reconciler never removes a held one. Creation and removal run
// under one lock, so a hold, a creation and a removal never interleave.
type Networks struct {
	d        Client
	serverID string

	mu    sync.Mutex
	holds map[string]int
}

// NewNetworks returns the network owner for d.
func NewNetworks(d Client, serverID state.ServerID) *Networks {
	return &Networks{d: d, serverID: string(serverID), holds: map[string]int{}}
}

// Ensure creates n with its canonical labels unless it exists. A network of
// that name with another protocol id is an error.
func (ns *Networks) Ensure(ctx context.Context, n state.NetworkSpec) error {
	ns.mu.Lock()
	defer ns.mu.Unlock()
	return ns.ensureLocked(ctx, n)
}

// Hold ensures n and keeps it from being removed until release is called.
// Holds nest.
func (ns *Networks) Hold(ctx context.Context, n state.NetworkSpec) (release func(), err error) {
	ns.mu.Lock()
	defer ns.mu.Unlock()
	if err := ns.ensureLocked(ctx, n); err != nil {
		return nil, err
	}
	name := string(n.Name)
	ns.holds[name]++
	var once sync.Once
	return func() {
		once.Do(func() {
			ns.mu.Lock()
			defer ns.mu.Unlock()
			if ns.holds[name]--; ns.holds[name] <= 0 {
				delete(ns.holds, name)
			}
		})
	}, nil
}

// Held reports whether name is held.
func (ns *Networks) Held(name string) bool {
	ns.mu.Lock()
	defer ns.mu.Unlock()
	return ns.holds[name] > 0
}

// RemoveUnlessHeld runs remove unless name is held.
func (ns *Networks) RemoveUnlessHeld(name string, remove func() error) (removed bool, err error) {
	ns.mu.Lock()
	defer ns.mu.Unlock()
	if ns.holds[name] > 0 {
		return false, nil
	}
	return true, remove()
}

func (ns *Networks) ensureLocked(ctx context.Context, n state.NetworkSpec) error {
	have, err := ns.d.ListNetworks(ctx)
	if err != nil {
		return fmt.Errorf("list networks: %w", err)
	}
	for _, h := range have {
		if h.Name != string(n.Name) {
			continue
		}
		if h.Labels[names.LabelNetwork] != string(n.ID) {
			return fmt.Errorf("network %s belongs to another environment", n.Name)
		}
		return nil
	}
	prefix, err := netip.ParsePrefix(string(n.Subnet))
	if err != nil {
		return fmt.Errorf("network %s: subnet: %w", n.Name, err)
	}
	labels := ManagedLabels(ns.serverID)
	labels[names.LabelNetwork] = string(n.ID)
	if _, err := ns.d.CreateNetwork(ctx, NetworkCreate{Name: string(n.Name), Subnet: prefix, Labels: labels}); err != nil {
		return fmt.Errorf("create network %s: %w", n.Name, err)
	}
	return nil
}

// networkRetryDelays bound the retries after "network not found" (about
// 1.75 s in total; the networks are ensured again in between).
var networkRetryDelays = []time.Duration{250 * time.Millisecond, 500 * time.Millisecond, time.Second}

// CreateContainer creates cc, attached to nets. While Docker answers
// "network not found" (the network was removed by something else, or the
// engine does not show it yet), it ensures nets again and retries.
func (ns *Networks) CreateContainer(ctx context.Context, cc *ContainerCreate, nets []state.NetworkSpec) (string, error) {
	return ns.retry(ctx, nets, func() (string, error) { return ns.d.CreateContainer(ctx, cc) })
}

// StartContainer starts a container attached to nets, retrying like CreateContainer.
func (ns *Networks) StartContainer(ctx context.Context, id string, nets []state.NetworkSpec) error {
	_, err := ns.retry(ctx, nets, func() (string, error) { return id, ns.d.StartContainer(ctx, id) })
	return err
}

func (ns *Networks) retry(ctx context.Context, nets []state.NetworkSpec, op func() (string, error)) (string, error) {
	id, err := op()
	for _, d := range networkRetryDelays {
		if !IsNetworkNotFound(err) {
			return id, err
		}
		t := time.NewTimer(d)
		select {
		case <-ctx.Done():
			t.Stop()
			return "", errors.Join(err, ctx.Err())
		case <-t.C:
		}
		for _, n := range nets {
			if eerr := ns.Ensure(ctx, n); eerr != nil {
				return "", errors.Join(err, eerr)
			}
		}
		id, err = op()
	}
	return id, err
}

// IsNetworkNotFound reports whether err is Docker's "network … not found".
func IsNetworkNotFound(err error) bool {
	return err != nil && errors.Is(err, ErrNotFound) && strings.Contains(strings.ToLower(err.Error()), "network")
}
