package session

import (
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"fmt"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// CommandWindow is how far a command's issuedAt may be from the daemon clock.
const CommandWindow = 60 * time.Second

// Verifier verifies a dashboard signature header over exact bytes
// (identity.KeySet.Verify).
type Verifier interface {
	Verify(header string, body []byte) (string, error)
}

// signedEvent is the data of a session.command / session.end server event.
type signedEvent struct {
	Payload   string `json:"payload"`
	Signature string `json:"signature"`
}

// ErrBadCommand is returned for commands that fail verification.
var ErrBadCommand = errors.New("session: bad command")

// commandGuard verifies commands for one session and remembers op ids.
type commandGuard struct {
	v       Verifier
	session state.SessionID
	seen    map[state.OpID]struct{}
	now     func() time.Time
}

func newCommandGuard(v Verifier, session state.SessionID, now func() time.Time) *commandGuard {
	return &commandGuard{v: v, session: session, seen: map[state.OpID]struct{}{}, now: now}
}

// open verifies the signature over the payload bytes before decoding it,
// then the session, the time window and that the op id is new.
func (g *commandGuard) open(event string, data jsontext.Value) (*state.SessionCommand, error) {
	var se signedEvent
	if err := json.Unmarshal(data, &se, json.RejectUnknownMembers(true)); err != nil {
		return nil, fmt.Errorf("%w: %w", ErrBadCommand, err)
	}
	if len(se.Payload) > 64<<10 {
		return nil, fmt.Errorf("%w: payload too large", ErrBadCommand)
	}
	if g.v == nil {
		return nil, fmt.Errorf("%w: no trusted keys", ErrBadCommand)
	}
	if _, err := g.v.Verify(se.Signature, []byte(se.Payload)); err != nil {
		return nil, fmt.Errorf("%w: signature: %w", ErrBadCommand, err)
	}
	cmd, err := state.Decode[state.SessionCommand]([]byte(se.Payload))
	if err != nil {
		return nil, fmt.Errorf("%w: %w", ErrBadCommand, err)
	}
	if cmd.Session != g.session {
		return nil, fmt.Errorf("%w: for session %s", ErrBadCommand, cmd.Session)
	}
	if d := g.now().Sub(cmd.IssuedAt); d > CommandWindow || d < -CommandWindow {
		return nil, fmt.Errorf("%w: issued %s ago", ErrBadCommand, d.Round(time.Second))
	}
	if (event == state.SessionEventEnd) != (cmd.Command == state.CommandEnd) {
		return nil, fmt.Errorf("%w: %s event carries %s", ErrBadCommand, event, cmd.Command)
	}
	if _, dup := g.seen[cmd.Op]; dup {
		return nil, fmt.Errorf("%w: op %s replayed", ErrBadCommand, cmd.Op)
	}
	g.seen[cmd.Op] = struct{}{}
	return cmd, nil
}
