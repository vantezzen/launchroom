package docker

import (
	"context"
	"errors"
	"fmt"
	"sync"

	"github.com/vantezzen/launchroom/daemon/internal/names"
)

// ErrNotIsolated is returned when a container was not created, started or
// connected because the network isolation (the firewall) could not be put
// in place: launchroomd fails closed (docs/05 §1, docs/09).
var ErrNotIsolated = errors.New("docker: network isolation is not in place")

// Isolator is a Client that checks network isolation itself: once a check
// is set, every container attached to a launchroom network is created,
// started or connected only after the check passed.
type Isolator interface {
	SetIsolation(check func(ctx context.Context) error)
}

// isolation holds the check (firewall.Manager.Sync) an Engine runs before a
// container joins a launchroom network.
type isolation struct {
	mu    sync.Mutex
	check func(context.Context) error
}

func (i *isolation) set(check func(context.Context) error) {
	i.mu.Lock()
	defer i.mu.Unlock()
	i.check = check
}

func (i *isolation) before(ctx context.Context, networks ...string) error {
	i.mu.Lock()
	check := i.check
	i.mu.Unlock()
	return CheckIsolation(ctx, check, networks...)
}

// CheckIsolation runs check when one of networks is a launchroom network
// (nil check: none installed). Its error wraps ErrNotIsolated.
func CheckIsolation(ctx context.Context, check func(context.Context) error, networks ...string) error {
	if check == nil || !anyManaged(networks) {
		return nil
	}
	if err := check(ctx); err != nil {
		return fmt.Errorf("%w: %w", ErrNotIsolated, err)
	}
	return nil
}

func anyManaged(networks []string) bool {
	for _, n := range networks {
		if names.Managed(n) {
			return true
		}
	}
	return false
}

// AttachedNetworks are the networks a container create attaches to.
func AttachedNetworks(cc *ContainerCreate) []string {
	var out []string
	if cc.HostConfig != nil && cc.HostConfig.NetworkMode != "" {
		out = append(out, string(cc.HostConfig.NetworkMode))
	}
	if cc.Networking != nil {
		for n := range cc.Networking.EndpointsConfig {
			out = append(out, n)
		}
	}
	return out
}

// NetworkNames are the networks c is attached to.
func (c Container) NetworkNames() []string {
	out := make([]string, 0, len(c.Networks))
	for n := range c.Networks {
		out = append(out, n)
	}
	return out
}
