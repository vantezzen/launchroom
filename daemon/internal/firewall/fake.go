package firewall

import (
	"errors"
	"sync"
)

// FakeBackend is an in-memory Backend for tests.
type FakeBackend struct {
	mu sync.Mutex
	// Live is the currently "installed" ruleset text ("" = no table).
	Live    string
	Applies int
	// Fail makes Apply fail.
	Fail bool
}

// Apply implements Backend.
func (f *FakeBackend) Apply(rs Ruleset) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.Fail {
		return errors.New("fake: apply failed")
	}
	f.Live = rs.String()
	f.Applies++
	return nil
}

// Check implements Backend.
func (f *FakeBackend) Check(rs Ruleset) ([]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.Live != rs.String() {
		return []string{"differs"}, nil
	}
	return nil, nil
}

// Remove implements Backend.
func (f *FakeBackend) Remove() error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.Live = ""
	return nil
}

// Tamper simulates something else changing the live rules.
func (f *FakeBackend) Tamper() {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.Live = "flushed"
}
