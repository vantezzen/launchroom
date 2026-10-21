package session

import (
	"context"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"fmt"
	"time"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Data result limits (docs/03 §11.3).
const (
	MaxRows        = 500
	MaxCell        = 8 << 10
	MaxResult      = 1 << 20
	DefaultPage    = 50
	commandTimeout = 20 * time.Second
	// statementTimeoutMs is the engine-side statement timeout.
	statementTimeoutMs = 15000
)

// Result is the JSON a data command answers with (reassembled from result frames).
type Result struct {
	Op             state.OpID          `json:"op"`
	Status         state.CommandStatus `json:"status"`
	Truncated      bool                `json:"truncated"`
	CellsTruncated bool                `json:"cellsTruncated,omitzero"`
	DurationMs     int64               `json:"durationMs"`
	Columns        []string            `json:"columns,omitzero"`
	Rows           [][]*string         `json:"rows,omitzero"`
	Affected       *int64              `json:"affected,omitzero"`
	Message        string              `json:"message,omitzero"`
	Schema         *SchemaInfo         `json:"schema,omitzero"`
	Keys           *KeyPage            `json:"keys,omitzero"`
	Value          *KeyValue           `json:"value,omitzero"`
	Reply          jsontext.Value      `json:"reply,omitzero"`
}

// SchemaInfo lists tables (SQL) or keyspaces (Redis).
type SchemaInfo struct {
	Engine state.DatabaseEngine `json:"engine"`
	Groups []SchemaGroup        `json:"groups"`
}

// SchemaGroup is a schema (Postgres), the database (MySQL) or a keyspace db (Redis).
type SchemaGroup struct {
	Name  string       `json:"name"`
	Items []SchemaItem `json:"items"`
	// Rows is the key count of a Redis keyspace.
	Rows *int64 `json:"rows,omitzero"`
}

// SchemaItem is one table-like object with its row estimate.
type SchemaItem struct {
	Name string `json:"name"`
	Kind string `json:"kind"`
	Rows *int64 `json:"rows,omitzero"`
}

// KeyPage is one SCAN page.
type KeyPage struct {
	Cursor string    `json:"cursor"`
	Items  []KeyItem `json:"items"`
}

// KeyItem is one scanned key.
type KeyItem struct {
	Key   string `json:"key"`
	Type  string `json:"type"`
	TTLMs int64  `json:"ttlMs"`
}

// KeyValue is one key's value (capped).
type KeyValue struct {
	Key       string     `json:"key"`
	Type      string     `json:"type"`
	TTLMs     int64      `json:"ttlMs"`
	Size      int64      `json:"size"`
	Truncated bool       `json:"truncated"`
	Text      *string    `json:"text,omitzero"`
	Items     []string   `json:"items,omitzero"`
	Entries   [][]string `json:"entries,omitzero"`
}

// worker runs data commands one at a time.
func (s *run) worker(ctx context.Context) {
	defer s.workerWG.Done()
	for cmd := range s.commands {
		started := s.o.Now()
		cctx, cancel := context.WithTimeout(ctx, commandTimeout)
		res := s.engine.run(cctx, cmd)
		cancel()
		res.Op = cmd.Op
		res.DurationMs = s.o.Now().Sub(started).Milliseconds()
		rows := len(res.Rows)
		if res.Keys != nil {
			rows = len(res.Keys.Items)
		}
		s.recordCommand(state.SessionCommandResult{Op: cmd.Op, Status: res.Status, Truncated: res.Truncated, Rows: rows, DurationMs: int(res.DurationMs)})
		if err := s.sendResult(ctx, res); err != nil {
			s.log.Warn("sending a result failed", "err", err)
		}
		select {
		case s.activity <- struct{}{}:
		default:
		}
	}
}

func (s *run) recordCommand(c state.SessionCommandResult) {
	s.resMu.Lock()
	defer s.resMu.Unlock()
	if len(s.res.Commands) < state.MaxSessionCommands {
		s.res.Commands = append(s.res.Commands, c)
	}
}

// sendResult encodes res (shrinking it under MaxResult) and sends it as result frames.
func (s *run) sendResult(ctx context.Context, res *Result) error {
	data, err := encodeResult(res)
	if err != nil {
		return err
	}
	parts := (len(data) + MaxData - 1) / MaxData
	for i := range parts {
		chunk := data[i*MaxData : min(len(data), (i+1)*MaxData)]
		s.resMu.Lock()
		s.res.BytesOut += int64(len(chunk))
		s.resMu.Unlock()
		if err := s.send(ctx, Payload{T: TResult, Op: res.Op, Part: i, Parts: parts, D: EncodeData(chunk)}); err != nil {
			return err
		}
	}
	return nil
}

// encodeResult marshals res, dropping trailing rows until it fits MaxResult.
func encodeResult(res *Result) ([]byte, error) {
	for {
		data, err := json.Marshal(res)
		if err != nil {
			return nil, err
		}
		if len(data) <= MaxResult {
			return data, nil
		}
		switch {
		case len(res.Rows) > 1:
			res.Rows = res.Rows[:len(res.Rows)/2]
			res.Truncated = true
		case len(res.Reply) > 0:
			res.Reply = nil
			res.Message = "The reply is larger than 1 MiB."
			res.Truncated = true
		default:
			return nil, errors.New("session: result too large")
		}
	}
}

// cell returns a capped copy of v.
func cell(v string, cut *bool) *string {
	if len(v) > MaxCell {
		v = v[:MaxCell]
		for !utf8.ValidString(v) && len(v) > 0 {
			v = v[:len(v)-1]
		}
		*cut = true
	}
	return &v
}

// engineRunner runs commands for one database engine.
type engineRunner struct {
	engine state.DatabaseEngine
	impl   dataEngine
}

type dataEngine interface {
	schema(ctx context.Context) *Result
	rows(ctx context.Context, t state.TableRef, page, size int) *Result
	query(ctx context.Context, statement string, allowWrites bool) *Result
	keys(ctx context.Context, pattern, cursor string) *Result
	key(ctx context.Context, key string) *Result
}

func (r *engineRunner) run(ctx context.Context, cmd *state.SessionCommand) *Result {
	switch cmd.Command {
	case state.CommandSchema:
		return r.impl.schema(ctx)
	case state.CommandRows:
		size := cmd.PageSize
		if size <= 0 {
			size = DefaultPage
		}
		return r.impl.rows(ctx, *cmd.Table, cmd.Page, size)
	case state.CommandQuery:
		return r.impl.query(ctx, cmd.Statement, cmd.AllowWrites)
	case state.CommandKeys:
		return r.impl.keys(ctx, cmd.Pattern, string(cmd.Cursor))
	case state.CommandKey:
		return r.impl.key(ctx, cmd.Key)
	}
	return refused("This command is not available here.")
}

func refused(msg string) *Result {
	return &Result{Status: state.CommandRefused, Message: msg}
}

func failed(msg string) *Result {
	return &Result{Status: state.CommandError, Message: msg}
}

func notForSQL(context.Context) *Result { return refused("Keys exist only in Redis and Valkey.") }

// sqlBase gives SQL engines the Redis-only commands' refusal.
type sqlBase struct{}

func (sqlBase) keys(ctx context.Context, _, _ string) *Result { return notForSQL(ctx) }
func (sqlBase) key(ctx context.Context, _ string) *Result     { return notForSQL(ctx) }

func newEngineRunner(e state.DatabaseEngine, env envMap, ex execer, container string) (*engineRunner, error) {
	var impl dataEngine
	var err error
	switch e {
	case state.EnginePostgres:
		impl, err = newPostgres(env, ex, container)
	case state.EngineMySQL:
		impl, err = newMySQL(env, ex, container, "mysql", false)
	case state.EngineMariaDB:
		impl, err = newMySQL(env, ex, container, "mariadb", true)
	case state.EngineRedis:
		impl, err = newRedis(env, ex, container, "redis-cli")
	case state.EngineValkey:
		impl, err = newRedis(env, ex, container, "valkey-cli")
	case state.EngineMongoDB:
		impl, err = newMongo(env, ex, container)
	case state.EngineClickHouse:
		impl, err = newClickHouse(env, ex, container)
	default:
		return nil, fmt.Errorf("%w: engine %q has no data browser", ErrRefused, e)
	}
	if err != nil {
		return nil, err
	}
	return &engineRunner{engine: e, impl: impl}, nil
}
