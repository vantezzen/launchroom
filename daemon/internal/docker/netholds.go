package docker

import (
	"context"
	"errors"
	"strings"
	"sync"
	"time"
)

// NetworkHolds coordinates the users of environment networks inside one
// launchroomd. A run_once task may create an environment network this
// server has no workloads in yet (a release command before the first
// rollout); the reconciler would otherwise see an undesired network without
// containers and remove it between the task creating it and attaching its
// container ("network lr-e_… not found"). Holders keep a network; the
// reconciler removes networks only through RemoveUnlessHeld, which runs the
// removal under the same lock, so a hold and a removal never interleave.
//
// The zero value is ready to use; a nil *NetworkHolds holds nothing.
type NetworkHolds struct {
	mu sync.Mutex
	n  map[string]int
}

// Hold keeps network name from being removed by the reconciler until release
// is called. Holds nest.
func (h *NetworkHolds) Hold(name string) (release func()) {
	if h == nil {
		return func() {}
	}
	h.mu.Lock()
	if h.n == nil {
		h.n = map[string]int{}
	}
	h.n[name]++
	h.mu.Unlock()
	var once sync.Once
	return func() {
		once.Do(func() {
			h.mu.Lock()
			defer h.mu.Unlock()
			if h.n[name]--; h.n[name] <= 0 {
				delete(h.n, name)
			}
		})
	}
}

// Held reports whether name is currently held.
func (h *NetworkHolds) Held(name string) bool {
	if h == nil {
		return false
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	return h.n[name] > 0
}

// RemoveUnlessHeld runs remove unless name is held; Hold waits while it runs.
func (h *NetworkHolds) RemoveUnlessHeld(name string, remove func() error) (removed bool, err error) {
	if h == nil {
		return true, remove()
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.n[name] > 0 {
		return false, nil
	}
	return true, remove()
}

// IsNetworkNotFound reports whether err is Docker's "network … not found"
// (e.g. from container create when the network vanished or is not visible yet).
func IsNetworkNotFound(err error) bool {
	return err != nil && errors.Is(err, ErrNotFound) && strings.Contains(strings.ToLower(err.Error()), "network")
}

// networkRetryDelays bound the container-create retries after "network not
// found" (about 1.75 s in total; the caller re-ensures the network in between).
var networkRetryDelays = []time.Duration{250 * time.Millisecond, 500 * time.Millisecond, time.Second}

// CreateWithNetworkRetry calls create and, while it fails with "network not
// found", calls ensure (re-create or re-verify the networks) and retries
// with bounded backoff. Other errors are returned at once.
func CreateWithNetworkRetry(ctx context.Context, create func() (string, error), ensure func(context.Context) error) (string, error) {
	id, err := create()
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
		if ensure != nil {
			if eerr := ensure(ctx); eerr != nil {
				return "", errors.Join(err, eerr)
			}
		}
		id, err = create()
	}
	return id, err
}
