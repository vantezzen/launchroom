// Package enrollment persists what the daemon learned when it enrolled: its
// server id and the control-plane key set it trusts (docs/03 §3).
package enrollment

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// FileName is the enrollment record inside the data dir.
const FileName = "enrollment.json"

// Record is the persisted enrollment.
type Record struct {
	ServerID         state.ServerID                   `json:"serverId"`
	ControlPlaneURL  string                           `json:"controlPlaneUrl"`
	ControlPlaneKeys map[state.KeyID]state.Ed25519Key `json:"controlPlaneKeys"`
	Status           state.ServerStatus               `json:"status"`
	PollIntervals    state.PollIntervals              `json:"pollIntervals"`
	// Nudge is the realtime nudge config from the enrollment response; the
	// daemon prefers GET /daemon/v1/config and falls back to this.
	Nudge      *state.NudgeConfig `json:"nudge,omitzero"`
	EnrolledAt time.Time          `json:"enrolledAt"`
}

// KeySet parses the trusted control-plane keys.
func (r *Record) KeySet() (identity.KeySet, error) {
	m := make(map[string]string, len(r.ControlPlaneKeys))
	for kid, k := range r.ControlPlaneKeys {
		m[string(kid)] = string(k)
	}
	return identity.ParseKeySet(m)
}

// Validate checks the record.
func (r *Record) Validate() error {
	if err := r.ServerID.Validate(); err != nil {
		return fmt.Errorf("enrollment: %w", err)
	}
	if _, err := r.KeySet(); err != nil {
		return fmt.Errorf("enrollment: %w", err)
	}
	return nil
}

// Load reads <dataDir>/enrollment.json. It returns (nil, nil) if the daemon has not enrolled yet.
func Load(dataDir string) (*Record, error) {
	path := filepath.Join(dataDir, FileName)
	data, err := os.ReadFile(path) //nolint:gosec // path inside the operator-configured data dir
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("enrollment: read: %w", err)
	}
	var r Record
	if err := state.DecodeStrict(data, &r); err != nil {
		return nil, fmt.Errorf("enrollment: %s: %w", path, err)
	}
	if err := r.Validate(); err != nil {
		return nil, err
	}
	return &r, nil
}

// Save writes the record atomically with mode 0600.
func Save(dataDir string, r *Record) error {
	if err := r.Validate(); err != nil {
		return err
	}
	data, err := state.Encode(r)
	if err != nil {
		return fmt.Errorf("enrollment: encode: %w", err)
	}
	if err := atomicfile.Write(filepath.Join(dataDir, FileName), data, 0o600); err != nil {
		return fmt.Errorf("enrollment: %w", err)
	}
	return nil
}
