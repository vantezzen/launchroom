package state

import (
	"encoding/base64"
	"encoding/json/v2"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
)

// ErrStaleVersion is returned when a NodeState is not newer than the current one.
var ErrStaleVersion = errors.New("state: version is not newer than the current state")

// Verifier verifies an X-LR-Signature header over raw bytes (identity.KeySet implements it).
type Verifier interface {
	Verify(signatureHeader string, body []byte) (kid string, err error)
}

// SignedState is a verified NodeState together with the exact bytes and
// signature it arrived with, so it can be persisted and re-verified offline.
type SignedState struct {
	State     *NodeState
	Raw       []byte
	Signature string
	KeyID     string
}

// VerifyNodeState verifies the signature over raw before parsing (docs/03
// §4.1), then strictly decodes and validates it and checks the server id.
func VerifyNodeState(raw []byte, signature string, v Verifier, serverID ServerID) (*SignedState, error) {
	if v == nil {
		return nil, errors.New("state: no verifier configured")
	}
	if signature == "" {
		return nil, errNotSigned
	}
	kid, err := v.Verify(signature, raw)
	if err != nil {
		return nil, fmt.Errorf("state: %w", err)
	}
	ns, err := DecodeNodeState(raw)
	if err != nil {
		return nil, &RejectedError{Version: peekVersion(raw), Err: err}
	}
	if ns.ServerID != serverID {
		return nil, &RejectedError{Version: ns.Version, Err: fmt.Errorf("state: NodeState is for server %s, this server is %s", ns.ServerID, serverID)}
	}
	return &SignedState{State: ns, Raw: raw, Signature: signature, KeyID: kid}, nil
}

// RejectedError is a correctly signed NodeState that launchroomd refuses
// because it does not decode or validate (or is for another server). The
// daemon keeps its last good state and reports the rejection
// (Report.stateRejected) so the dashboard can show it.
type RejectedError struct {
	// Version of the rejected document, 0 if unreadable.
	Version int64
	Err     error
}

func (e *RejectedError) Error() string { return e.Err.Error() }

// Unwrap returns the decode or validation error.
func (e *RejectedError) Unwrap() error { return e.Err }

// peekVersion reads "version" leniently from a document that failed strict
// decoding (for the rejection report only; never used to act on the state).
func peekVersion(raw []byte) int64 {
	var v struct {
		Version int64 `json:"version"`
	}
	if json.Unmarshal(raw, &v) != nil || v.Version < 0 {
		return 0
	}
	return v.Version
}

// storeFormat identifies the on-disk envelope.
const storeFormat = "launchroom.state/v1"

// storedState is the on-disk envelope. Body holds the exact signed bytes
// (base64) because re-serializing JSON would break the signature.
type storedState struct {
	Format    string    `json:"format"`
	Signature string    `json:"signature"`
	Body      string    `json:"body"`
	SavedAt   time.Time `json:"savedAt"`
}

// Store persists the current NodeState in <data>/state.json (0600) so the
// daemon can restart offline and still trust it. It enforces monotonic versions.
type Store struct {
	path     string
	verifier Verifier
	serverID ServerID

	mu      sync.Mutex
	current *SignedState
}

// NewStore returns a store with no current state; call Load to read the file.
func NewStore(path string, v Verifier, serverID ServerID) *Store {
	return &Store{path: path, verifier: v, serverID: serverID}
}

// Load reads and re-verifies the persisted state. A missing file is not an
// error. A file that fails verification is an error and is NOT used (fail
// closed); the daemon then starts from version 0 and refetches.
func (s *Store) Load() error {
	data, err := os.ReadFile(s.path)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("state: read %s: %w", s.path, err)
	}
	var st storedState
	if err := DecodeStrict(data, &st); err != nil {
		return fmt.Errorf("state: %s: %w", s.path, err)
	}
	if st.Format != storeFormat {
		return fmt.Errorf("state: %s: unknown format %q", s.path, truncate(st.Format))
	}
	raw, err := base64.StdEncoding.Strict().DecodeString(st.Body)
	if err != nil {
		return fmt.Errorf("state: %s: body is not base64: %w", s.path, err)
	}
	ss, err := VerifyNodeState(raw, st.Signature, s.verifier, s.serverID)
	if err != nil {
		return fmt.Errorf("state: persisted state rejected: %w", err)
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	s.current = ss
	return nil
}

// Current returns the current verified state, or nil.
func (s *Store) Current() *SignedState {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.current
}

// Version returns the current version (0 if none).
func (s *Store) Version() int64 {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.current == nil {
		return 0
	}
	return s.current.State.Version
}

// Accept re-verifies ss, enforces version > current, persists it atomically
// and makes it current.
func (s *Store) Accept(ss *SignedState) error {
	if ss == nil {
		return errors.New("state: nil state")
	}
	verified, err := VerifyNodeState(ss.Raw, ss.Signature, s.verifier, s.serverID)
	if err != nil {
		return err
	}

	s.mu.Lock()
	defer s.mu.Unlock()
	if s.current != nil && verified.State.Version <= s.current.State.Version {
		return fmt.Errorf("%w: got %d, have %d", ErrStaleVersion, verified.State.Version, s.current.State.Version)
	}
	data, err := Encode(storedState{
		Format:    storeFormat,
		Signature: verified.Signature,
		Body:      base64.StdEncoding.EncodeToString(verified.Raw),
		SavedAt:   time.Now().UTC(),
	})
	if err != nil {
		return fmt.Errorf("state: encode: %w", err)
	}
	if err := atomicfile.Write(s.path, data, 0o600); err != nil {
		return fmt.Errorf("state: persist: %w", err)
	}
	s.current = verified
	return nil
}
