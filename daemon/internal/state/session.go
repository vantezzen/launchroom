package state

import (
	"regexp"
	"strings"
	"time"
	"unicode/utf8"
)

// Interactive sessions (docs/03 §11, D35): a console in a workload container
// or a database data session, relayed through Reverb on the private channel
// "private-session.<session id>".

// KindSessionCommand is the kind of a signed dashboard → daemon session command.
const KindSessionCommand = "SessionCommand"

// SessionChannelPrefix + the session id is the session's private Pusher channel.
const SessionChannelPrefix = "private-session."

// Pusher server events the dashboard sends on a session channel.
const (
	SessionEventCommand = "session.command"
	SessionEventEnd     = "session.end"
)

// Limits of session documents.
const (
	MaxSessionStatement = 16 << 10
	MaxSessionKey       = 1024
	MaxSessionPattern   = 256
	MaxSessionCommands  = 1000
)

var (
	reSessionID   = idRegexp("ses")
	reOpID        = idRegexp("op")
	reCursor      = regexp.MustCompile(`^[0-9]{1,20}$`)
	reTableName   = regexp.MustCompile("^[^\\x00-\\x1f\\x7f\"`]{1,63}$")
	sessionKinds  = []string{string(SessionConsole), string(SessionData)}
	shellPaths    = []string{string(ShellSh), string(ShellBash), string(ShellAsh), string(ShellZsh)}
	endReasons    = []string{"closed", "exited", "idle", "deadline", "disconnected", "ended", "never_connected", "refused", "error"}
	commandTypes  = []string{string(CommandEnd), string(CommandSchema), string(CommandRows), string(CommandKeys), string(CommandKey), string(CommandQuery)}
	commandStatus = []string{string(CommandOK), string(CommandError), string(CommandRefused)}
)

// SessionID is an interactive session's public id ("ses_<ULID>").
type SessionID string

// ProtocolSchema implements SchemaDescriber.
func (SessionID) ProtocolSchema() ScalarSchema { return strSchema(reSessionID, 0) }

// Validate checks the grammar.
func (v SessionID) Validate() error { return checkPattern("session id", string(v), reSessionID, 0) }

// SessionChannelFor returns the private channel of a session.
func SessionChannelFor(id SessionID) DaemonChannel {
	return DaemonChannel(SessionChannelPrefix + string(id))
}

// OpID identifies one dashboard command ("op_<ULID>"), single use.
type OpID string

// ProtocolSchema implements SchemaDescriber.
func (OpID) ProtocolSchema() ScalarSchema { return strSchema(reOpID, 0) }

// Validate checks the grammar.
func (v OpID) Validate() error { return checkPattern("op id", string(v), reOpID, 0) }

// SessionKind is what a session does.
type SessionKind string

// Session kinds.
const (
	SessionConsole SessionKind = "console"
	SessionData    SessionKind = "data"
)

// ProtocolSchema implements SchemaDescriber.
func (SessionKind) ProtocolSchema() ScalarSchema { return enumSchema(sessionKinds) }

// Validate checks the value.
func (v SessionKind) Validate() error { return checkEnum("session kind", string(v), sessionKinds) }

// ShellPath is one of the allow-listed console shells (docs/03 §11.4).
type ShellPath string

// Allowed shells.
const (
	ShellSh   ShellPath = "/bin/sh"
	ShellBash ShellPath = "/bin/bash"
	ShellAsh  ShellPath = "/bin/ash"
	ShellZsh  ShellPath = "/bin/zsh"
)

// ProtocolSchema implements SchemaDescriber.
func (ShellPath) ProtocolSchema() ScalarSchema { return enumSchema(shellPaths) }

// Validate checks the value.
func (v ShellPath) Validate() error { return checkEnum("shell", string(v), shellPaths) }

// SessionEndReason says why a session ended.
type SessionEndReason string

// End reasons.
const (
	EndClosed         SessionEndReason = "closed"
	EndExited         SessionEndReason = "exited"
	EndIdle           SessionEndReason = "idle"
	EndDeadline       SessionEndReason = "deadline"
	EndDisconnected   SessionEndReason = "disconnected"
	EndEnded          SessionEndReason = "ended"
	EndNeverConnected SessionEndReason = "never_connected"
	EndRefused        SessionEndReason = "refused"
	EndError          SessionEndReason = "error"
)

// ProtocolSchema implements SchemaDescriber.
func (SessionEndReason) ProtocolSchema() ScalarSchema { return enumSchema(endReasons) }

// Validate checks the value.
func (v SessionEndReason) Validate() error { return checkEnum("end reason", string(v), endReasons) }

// SessionCommandType is the command of a SessionCommand.
type SessionCommandType string

// Session commands.
const (
	CommandEnd    SessionCommandType = "end"
	CommandSchema SessionCommandType = "schema"
	CommandRows   SessionCommandType = "rows"
	CommandKeys   SessionCommandType = "keys"
	CommandKey    SessionCommandType = "key"
	CommandQuery  SessionCommandType = "query"
)

// ProtocolSchema implements SchemaDescriber.
func (SessionCommandType) ProtocolSchema() ScalarSchema { return enumSchema(commandTypes) }

// Validate checks the value.
func (v SessionCommandType) Validate() error { return checkEnum("command", string(v), commandTypes) }

// CommandStatus is the outcome of one data command.
type CommandStatus string

// Command outcomes.
const (
	CommandOK      CommandStatus = "ok"
	CommandError   CommandStatus = "error"
	CommandRefused CommandStatus = "refused"
)

// ProtocolSchema implements SchemaDescriber.
func (CommandStatus) ProtocolSchema() ScalarSchema { return enumSchema(commandStatus) }

// Validate checks the value.
func (v CommandStatus) Validate() error { return checkEnum("command status", string(v), commandStatus) }

// SessionCursor is a Redis SCAN cursor (digits).
type SessionCursor string

// ProtocolSchema implements SchemaDescriber.
func (SessionCursor) ProtocolSchema() ScalarSchema { return strSchema(reCursor, 20) }

// Validate checks the grammar.
func (v SessionCursor) Validate() error { return checkPattern("cursor", string(v), reCursor, 20) }

// TableName is a schema or table name (1–63 bytes; no control characters,
// double quotes or backticks, so quoting by doubling is always safe).
type TableName string

// ProtocolSchema implements SchemaDescriber.
func (TableName) ProtocolSchema() ScalarSchema { return strSchema(reTableName, 63) }

// Validate checks the grammar.
func (v TableName) Validate() error {
	if !utf8.ValidString(string(v)) {
		return checkPattern("table name", "\x00", reTableName, 63)
	}
	return checkPattern("table name", string(v), reTableName, 63)
}

// SessionSpec is the spec of a "session" task (docs/03 §11.1).
type SessionSpec struct {
	Session  SessionID     `json:"session"`
	Kind     SessionKind   `json:"kind"`
	Workload WorkloadID    `json:"workload"`
	Channel  DaemonChannel `json:"channel"`
	// Key is the sealed TaskToken (purpose "session") holding the 32-byte
	// session key (base64url without padding).
	Key Sealed `json:"key"`
	// IdleTimeout ends the session without input (console) or commands (data).
	IdleTimeout Duration `json:"idleTimeout"`
	// Shell is the console shell; empty picks one automatically.
	Shell ShellPath `json:"shell,omitzero"`
	// Cols and Rows are the initial terminal size (console).
	Cols int `json:"cols,omitzero" jsonschema:"minimum=2,maximum=500"`
	Rows int `json:"rows,omitzero" jsonschema:"minimum=2,maximum=200"`
	// Record ships the (masked) output as the task's log (console, opt-in).
	Record bool `json:"record,omitzero"`
	// Engine is the database engine (data sessions only).
	Engine DatabaseEngine `json:"engine,omitzero"`
}

// TaskType implements TaskSpec.
func (*SessionSpec) TaskType() TaskType { return TaskSession }

// Validate implements TaskSpec.
func (s *SessionSpec) Validate() error {
	var e errs
	e.check("session", s.Session)
	e.check("kind", s.Kind)
	e.check("workload", s.Workload)
	e.check("channel", s.Channel)
	if s.Channel != SessionChannelFor(s.Session) {
		e.addf("channel", "must be %s", SessionChannelFor(s.Session))
	}
	e.check("key", s.Key)
	e.check("idleTimeout", s.IdleTimeout)
	if d := s.IdleTimeout.Std(); d > 0 && (d < time.Minute || d > time.Hour) {
		e.addf("idleTimeout", "must be between 1m and 1h")
	}
	if s.Cols != 0 && (s.Cols < 2 || s.Cols > 500) {
		e.addf("cols", "must be between 2 and 500")
	}
	if s.Rows != 0 && (s.Rows < 2 || s.Rows > 200) {
		e.addf("rows", "must be between 2 and 200")
	}
	switch s.Kind {
	case SessionConsole:
		if s.Shell != "" {
			e.check("shell", s.Shell)
		}
		if s.Engine != "" {
			e.addf("engine", "is only valid for data sessions")
		}
	case SessionData:
		e.check("engine", s.Engine)
		if s.Shell != "" || s.Record || s.Cols != 0 || s.Rows != 0 {
			e.addf("kind", "data sessions take no shell, size or record")
		}
	}
	return e.err()
}

// SessionCommandResult is the outcome of one data command (TaskCompletion.session.commands).
type SessionCommandResult struct {
	Op         OpID          `json:"op"`
	Status     CommandStatus `json:"status"`
	Truncated  bool          `json:"truncated,omitzero"`
	Rows       int           `json:"rows" jsonschema:"minimum=0"`
	DurationMs int           `json:"durationMs" jsonschema:"minimum=0"`
}

// SessionResult is reported for finished session tasks.
type SessionResult struct {
	// StartedAt is when the shell started or the first command ran.
	StartedAt time.Time              `json:"startedAt,omitzero"`
	EndReason SessionEndReason       `json:"endReason"`
	ExitCode  *int                   `json:"exitCode,omitzero" jsonschema:"nullable"`
	BytesIn   int64                  `json:"bytesIn" jsonschema:"minimum=0"`
	BytesOut  int64                  `json:"bytesOut" jsonschema:"minimum=0"`
	Commands  []SessionCommandResult `json:"commands,omitzero" jsonschema:"maxItems=1000"`
}

func (r *SessionResult) validate(e *errs) {
	e.check("session.endReason", r.EndReason)
	if r.BytesIn < 0 || r.BytesOut < 0 {
		e.addf("session.bytes", "must be >= 0")
	}
	if len(r.Commands) > MaxSessionCommands {
		e.addf("session.commands", "at most %d", MaxSessionCommands)
	}
	for i, c := range r.Commands {
		p := idx("session.commands", i)
		e.check(p+".op", c.Op)
		e.check(p+".status", c.Status)
		if c.Rows < 0 || c.DurationMs < 0 {
			e.addf(p, "rows and durationMs must be >= 0")
		}
	}
}

// TableRef names a table (schema optional: MySQL has none, Postgres defaults to "public").
type TableRef struct {
	Schema TableName `json:"schema,omitzero"`
	Name   TableName `json:"name"`
}

// SessionCommand is a dashboard → daemon command on a session channel,
// delivered as the payload of a signed Pusher server event (docs/03 §11.2).
type SessionCommand struct {
	TypeMeta
	Session  SessionID          `json:"session"`
	Op       OpID               `json:"op"`
	IssuedAt time.Time          `json:"issuedAt"`
	Command  SessionCommandType `json:"command"`
	// Table, Page and PageSize select rows to browse ("rows").
	Table    *TableRef `json:"table,omitzero" jsonschema:"nullable"`
	Page     int       `json:"page,omitzero" jsonschema:"minimum=0,maximum=100000"`
	PageSize int       `json:"pageSize,omitzero" jsonschema:"minimum=1,maximum=100"`
	// Pattern and Cursor scan keys ("keys").
	Pattern string        `json:"pattern,omitzero" jsonschema:"maxLength=256"`
	Cursor  SessionCursor `json:"cursor,omitzero"`
	// Key is the key to read ("key").
	Key string `json:"key,omitzero" jsonschema:"maxLength=1024"`
	// Statement is the SQL (or Redis command line) to run ("query").
	Statement   string `json:"statement,omitzero" jsonschema:"maxLength=16384"`
	AllowWrites bool   `json:"allowWrites,omitzero"`
	// Reason is shown when the session ends ("end").
	Reason string `json:"reason,omitzero" jsonschema:"maxLength=200"`
}

// Validate checks the document.
func (c *SessionCommand) Validate() error {
	var e errs
	c.check(&e, KindSessionCommand)
	e.check("session", c.Session)
	e.check("op", c.Op)
	if c.IssuedAt.IsZero() {
		e.addf("issuedAt", "is required")
	}
	e.check("command", c.Command)
	if c.Table != nil {
		if c.Table.Schema != "" {
			e.check("table.schema", c.Table.Schema)
		}
		e.check("table.name", c.Table.Name)
	}
	if c.Page < 0 || c.Page > 100000 {
		e.addf("page", "must be between 0 and 100000")
	}
	if c.PageSize != 0 && (c.PageSize < 1 || c.PageSize > 100) {
		e.addf("pageSize", "must be between 1 and 100")
	}
	if c.Cursor != "" {
		e.check("cursor", c.Cursor)
	}
	checkText(&e, "pattern", c.Pattern, MaxSessionPattern)
	checkText(&e, "key", c.Key, MaxSessionKey)
	checkText(&e, "statement", c.Statement, MaxSessionStatement)
	checkText(&e, "reason", c.Reason, 200)
	for _, f := range []struct{ name, v string }{{"pattern", c.Pattern}, {"key", c.Key}, {"statement", c.Statement}, {"reason", c.Reason}} {
		if !utf8.ValidString(f.v) || strings.ContainsRune(f.v, 0) {
			e.addf(f.name, "must be UTF-8 without NUL")
		}
	}
	switch c.Command {
	case CommandRows:
		if c.Table == nil {
			e.addf("table", "is required for rows")
		}
	case CommandKey:
		if c.Key == "" {
			e.addf("key", "is required for key")
		}
	case CommandQuery:
		if strings.TrimSpace(c.Statement) == "" {
			e.addf("statement", "is required for query")
		}
	}
	if c.Command != CommandRows && (c.Table != nil || c.Page != 0 || c.PageSize != 0) {
		e.addf("table", "only valid for rows")
	}
	if c.Command != CommandKeys && (c.Pattern != "" || c.Cursor != "") {
		e.addf("pattern", "only valid for keys")
	}
	if c.Command != CommandKey && c.Key != "" {
		e.addf("key", "only valid for key")
	}
	if c.Command != CommandQuery && (c.Statement != "" || c.AllowWrites) {
		e.addf("statement", "only valid for query")
	}
	if c.Command != CommandEnd && c.Reason != "" {
		e.addf("reason", "only valid for end")
	}
	return e.err()
}
