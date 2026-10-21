package session

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

const (
	sid   = state.SessionID("ses_01J9Z8X7W6V5T4S3R2Q1P0N9S2")
	other = state.SessionID("ses_01J9Z8X7W6V5T4S3R2Q1P0N9S3")
	wl    = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
)

func frameJSON(t *testing.T, f Frame) jsontext.Value {
	t.Helper()
	b, err := json.Marshal(f)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func TestFramesRoundTripAndRejections(t *testing.T) {
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	browser := NewCodec(key, sid, false)
	daemon := NewCodec(key, sid, true)

	f, err := browser.Seal(Payload{T: TIn, D: EncodeData([]byte("echo hi\n"))})
	if err != nil {
		t.Fatal(err)
	}
	p, err := daemon.Open(frameJSON(t, f))
	if err != nil || p.T != TIn || p.S != 1 {
		t.Fatalf("open: %+v %v", p, err)
	}
	if b, _ := DecodeData(p.D); string(b) != "echo hi\n" {
		t.Fatalf("data %q", b)
	}
	// Replayed frame (same sequence).
	if _, err := daemon.Open(frameJSON(t, f)); !errors.Is(err, ErrBadFrame) {
		t.Fatalf("replay accepted: %v", err)
	}
	// Tampered payload.
	f2, _ := browser.Seal(Payload{T: TIn, D: EncodeData([]byte("ls"))})
	tampered := f2
	tampered.P = strings.Replace(f2.P, `"in"`, `"bye"`, 1)
	if _, err := daemon.Open(frameJSON(t, tampered)); !errors.Is(err, ErrBadFrame) {
		t.Fatal("tampered frame accepted")
	}
	// Reflection: the daemon's own d2b frame is not a b2d frame.
	own, _ := daemon.Seal(Payload{T: TOut, D: EncodeData([]byte("x"))})
	if _, err := NewCodec(key, sid, true).Open(frameJSON(t, own)); !errors.Is(err, ErrBadFrame) {
		t.Fatal("reflected frame accepted")
	}
	// Another session's key context.
	if _, err := NewCodec(key, other, true).Open(frameJSON(t, f2)); !errors.Is(err, ErrBadFrame) {
		t.Fatal("frame of another session accepted")
	}
	// Wrong key.
	k2 := make([]byte, 32)
	if _, err := NewCodec(k2, sid, true).Open(frameJSON(t, f2)); !errors.Is(err, ErrBadFrame) {
		t.Fatal("frame under another key accepted")
	}
	// Gaps are fine (frames sent before subscribing are lost), going back is not.
	f3, _ := browser.Seal(Payload{T: TPing})
	if _, err := daemon.Open(frameJSON(t, f3)); err != nil {
		t.Fatalf("gap refused: %v", err)
	}
	if _, err := daemon.Open(frameJSON(t, f2)); !errors.Is(err, ErrBadFrame) {
		t.Fatal("older frame accepted")
	}
	// Unknown payload fields are refused even with a valid MAC.
	raw := `{"s":99,"t":"in","evil":1}`
	if _, err := daemon.Open(frameJSON(t, Frame{P: raw, M: MAC(key, DirBrowserToDaemon, sid, raw)})); !errors.Is(err, ErrBadFrame) {
		t.Fatal("unknown field accepted")
	}
	if _, err := DecodeData(strings.Repeat("A", 6000)); err == nil {
		t.Fatal("oversized data accepted")
	}
}

func TestBucket(t *testing.T) {
	now := time.Unix(0, 0)
	b := newBucket(100, 200, now)
	if !b.take(200, now) || b.take(1, now) {
		t.Fatal("burst")
	}
	if !b.take(100, now.Add(time.Second)) {
		t.Fatal("refill")
	}
}

type signer struct {
	key ed25519.PrivateKey
	ks  identity.KeySet
}

func newSigner(t *testing.T) signer {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return signer{key: priv, ks: identity.KeySet{"cp1": pub}}
}

func (s signer) event(t *testing.T, cmd state.SessionCommand) jsontext.Value {
	t.Helper()
	cmd.TypeMeta = state.NewTypeMeta(state.KindSessionCommand)
	payload, err := json.Marshal(cmd)
	if err != nil {
		t.Fatal(err)
	}
	sig, err := identity.SignResponse("cp1", s.key, payload)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(signedEvent{Payload: string(payload), Signature: sig})
	return b
}

func TestCommandVerification(t *testing.T) {
	s := newSigner(t)
	now := time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)
	g := newCommandGuard(s.ks, sid, func() time.Time { return now })
	q := state.SessionCommand{Session: sid, Op: "op_01J9Z8X7W6V5T4S3R2Q1P0N9T1", IssuedAt: now, Command: state.CommandQuery, Statement: "SELECT 1"}

	if cmd, err := g.open(state.SessionEventCommand, s.event(t, q)); err != nil || cmd.Statement != "SELECT 1" {
		t.Fatalf("valid command: %v", err)
	}
	if _, err := g.open(state.SessionEventCommand, s.event(t, q)); !errors.Is(err, ErrBadCommand) {
		t.Fatal("replayed op accepted")
	}
	cases := map[string]jsontext.Value{
		"expired":       s.event(t, withOp(q, "T2", func(c *state.SessionCommand) { c.IssuedAt = now.Add(-2 * time.Minute) })),
		"future":        s.event(t, withOp(q, "T3", func(c *state.SessionCommand) { c.IssuedAt = now.Add(2 * time.Minute) })),
		"wrong session": s.event(t, withOp(q, "T4", func(c *state.SessionCommand) { c.Session = other })),
		"end as command": s.event(t, withOp(q, "T5", func(c *state.SessionCommand) {
			c.Command, c.Statement = state.CommandEnd, ""
		})),
	}
	forged := newSigner(t).event(t, withOp(q, "T6", func(*state.SessionCommand) {}))
	cases["forged signature"] = forged
	var tampered signedEvent
	_ = json.Unmarshal(s.event(t, withOp(q, "T7", func(*state.SessionCommand) {})), &tampered)
	tampered.Payload = strings.Replace(tampered.Payload, "SELECT 1", "DROP TABLE x", 1)
	cases["tampered payload"], _ = json.Marshal(tampered)
	for name, ev := range cases {
		if _, err := g.open(state.SessionEventCommand, ev); !errors.Is(err, ErrBadCommand) {
			t.Errorf("%s accepted: %v", name, err)
		}
	}
	end := withOp(q, "T8", func(c *state.SessionCommand) { c.Command, c.Statement = state.CommandEnd, "" })
	if _, err := g.open(state.SessionEventEnd, s.event(t, end)); err != nil {
		t.Fatalf("end: %v", err)
	}
	if _, err := newCommandGuard(nil, sid, time.Now).open(state.SessionEventCommand, s.event(t, q)); err == nil {
		t.Fatal("no trusted keys accepted")
	}
}

func withOp(c state.SessionCommand, suffix string, f func(*state.SessionCommand)) state.SessionCommand {
	c.Op = state.OpID("op_01J9Z8X7W6V5T4S3R2Q1P0N9" + suffix)
	f(&c)
	return c
}

// fakeDocker implements session.Docker for policy tests.
type fakeDocker struct {
	docker.Client
	containers []docker.Container
	paths      map[string]bool
}

func (f *fakeDocker) ListContainers(_ context.Context, labels map[string]string) ([]docker.Container, error) {
	var out []docker.Container
	for _, c := range f.containers {
		if c.Labels[docker.LabelWorkload] == labels[docker.LabelWorkload] {
			out = append(out, c)
		}
	}
	return out, nil
}

func (f *fakeDocker) PathExists(_ context.Context, _, p string) (bool, error) { return f.paths[p], nil }
func (f *fakeDocker) ExecTTY(context.Context, string, docker.TTYOptions) (*docker.TTYExec, error) {
	return nil, errors.New("not in tests")
}

func (f *fakeDocker) ExecStream(context.Context, string, docker.ExecOptions) (docker.ExecResult, error) {
	return docker.ExecResult{}, errors.New("not in tests")
}

func managed(labels map[string]string) docker.Container {
	l := map[string]string{docker.LabelManaged: "true", docker.LabelServer: "srv_x", docker.LabelWorkload: string(wl)}
	for k, v := range labels {
		l[k] = v
	}
	return docker.Container{ID: "c1", Name: "lr-wl", Labels: l, Running: true}
}

func TestTargetPolicy(t *testing.T) {
	spec := &state.SessionSpec{Session: sid, Kind: state.SessionConsole, Workload: wl}
	in := func(state.WorkloadID) bool { return true }
	cases := map[string]struct {
		fd      *fakeDocker
		inState func(state.WorkloadID) bool
		refused bool
	}{
		"not in desired state": {&fakeDocker{containers: []docker.Container{managed(nil)}}, func(state.WorkloadID) bool { return false }, true},
		"system container":     {&fakeDocker{containers: []docker.Container{managed(map[string]string{docker.LabelSystem: "caddy"})}}, in, true},
		"task container":       {&fakeDocker{containers: []docker.Container{managed(map[string]string{docker.LabelTask: "tsk_x"})}}, in, true},
		"no container":         {&fakeDocker{}, in, true},
		"workload container":   {&fakeDocker{containers: []docker.Container{managed(nil)}}, in, false},
	}
	for name, c := range cases {
		r := &run{o: Options{Docker: c.fd, InDesiredState: c.inState}, spec: spec}
		_, err := r.target(context.Background())
		if c.refused != (err != nil && errors.Is(err, ErrRefused)) {
			t.Errorf("%s: err %v", name, err)
		}
	}
}

func TestShellPolicy(t *testing.T) {
	fd := &fakeDocker{paths: map[string]bool{"/bin/sh": true, "/bin/ash": true}}
	r := &run{o: Options{Docker: fd}, spec: &state.SessionSpec{}, container: "c1"}
	if sh, err := r.pickShell(context.Background()); err != nil || sh != "/bin/ash" {
		t.Fatalf("auto: %q %v", sh, err)
	}
	r.spec.Shell = "/bin/bash"
	if _, err := r.pickShell(context.Background()); err == nil {
		t.Fatal("missing bash accepted")
	}
	r.spec.Shell = "/usr/bin/python3"
	if _, err := r.pickShell(context.Background()); !errors.Is(err, ErrRefused) {
		t.Fatalf("shell outside the allow-list: %v", err)
	}
	r.spec.Shell = ""
	r.o.Docker = &fakeDocker{paths: map[string]bool{}}
	if _, err := r.pickShell(context.Background()); err == nil || !strings.Contains(err.Error(), "no shell") {
		t.Fatalf("distroless: %v", err)
	}
}

func TestHandlerRefusesDisallowedKind(t *testing.T) {
	h := Handler(Options{Allowed: func(k state.SessionKind) bool { return k == state.SessionData }})
	res, err := h(context.Background(), state.Task{ID: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9S1", Spec: &state.SessionSpec{Kind: state.SessionConsole}})
	if err == nil || res.Session == nil || res.Session.EndReason != state.EndRefused {
		t.Fatalf("got %+v %v", res.Session, err)
	}
	var _ = tasks.Handler(h)
}

func TestRedisPolicy(t *testing.T) {
	for _, c := range []struct {
		line   string
		writes bool
		ok     bool
	}{
		{"GET user:1", false, true},
		{"get user:1", false, true},
		{"SET user:1 x", false, false},
		{"SET user:1 x", true, true},
		{"FLUSHALL", true, true},
		{"CONFIG SET dir /tmp", true, false},
		{"shutdown", true, false},
		{"MONITOR", true, false},
		{"EVAL \"return 1\" 0", false, false},
	} {
		args, err := splitRedisArgs(c.line)
		if err != nil {
			t.Fatal(err)
		}
		if got := redisCommandAllowed(args, c.writes) == ""; got != c.ok {
			t.Errorf("%q writes=%v: allowed=%v", c.line, c.writes, got)
		}
	}
	args, err := splitRedisArgs(`SET "a key" 'it\'s' "\x41\n"`)
	if err != nil || len(args) != 4 || args[1] != "a key" || args[2] != "it's" || args[3] != "A\n" {
		t.Fatalf("tokens %q %v", args, err)
	}
	if _, err := splitRedisArgs(`GET "open`); err == nil {
		t.Fatal("unbalanced quote accepted")
	}
}

func TestIdentifierGrammar(t *testing.T) {
	for _, bad := range []string{"", `a"b`, "a`b", "a\nb", "a\x00", strings.Repeat("x", 64)} {
		if state.TableName(bad).Validate() == nil {
			t.Errorf("%q accepted", bad)
		}
	}
	for _, good := range []string{"users", "Order Items", "über", "a-b.c"} {
		if err := state.TableName(good).Validate(); err != nil {
			t.Errorf("%q: %v", good, err)
		}
	}
	if q := quoteIdent("my table", `"`); q != `"my table"` {
		t.Fatal(q)
	}
}

func TestParsePsqlCSV(t *testing.T) {
	null, marker := "__N__", "__E__"
	out := "id,email,note\n1,a@x.io,__N__\n2,\"b,\"\"q\"\"@x.io\",\"multi\nline\"\n" + marker + " 2\n"
	r := parsePsqlCSV([]byte(out), null, marker, false)
	if r.Status != state.CommandOK || len(r.Columns) != 3 || len(r.Rows) != 2 || r.Truncated {
		t.Fatalf("%+v", r)
	}
	if r.Rows[0][2] != nil || *r.Rows[1][1] != `b,"q"@x.io` || *r.Rows[1][2] != "multi\nline" {
		t.Fatalf("cells %v", r.Rows)
	}
	// No rows: affected comes from ROW_COUNT.
	r = parsePsqlCSV([]byte(marker+" 3\n"), null, marker, false)
	if r.Affected == nil || *r.Affected != 3 || r.Columns != nil {
		t.Fatalf("affected %+v", r)
	}
	// Empty SELECT keeps its columns.
	r = parsePsqlCSV([]byte("id\n"+marker+" 0\n"), null, marker, false)
	if len(r.Columns) != 1 || r.Rows == nil || len(r.Rows) != 0 || r.Affected != nil {
		t.Fatalf("empty select %+v", r)
	}
	// Row cap and a cut stream.
	var b strings.Builder
	b.WriteString("n\n")
	for i := range 700 {
		b.WriteString(strings.Repeat("x", i%3+1) + "\n")
	}
	r = parsePsqlCSV([]byte(b.String()+"\"unterminated"), null, marker, true)
	if len(r.Rows) != MaxRows || !r.Truncated {
		t.Fatalf("cap: %d %v", len(r.Rows), r.Truncated)
	}
	// Long cells are cut.
	r = parsePsqlCSV([]byte("v\n"+strings.Repeat("y", MaxCell+10)+"\n"+marker+" 1\n"), null, marker, false)
	if !r.CellsTruncated || len(*r.Rows[0][0]) != MaxCell {
		t.Fatal("cell cap")
	}
}

func TestSQLFailureReadOnly(t *testing.T) {
	if r := sqlFailure("ERROR:  cannot execute INSERT in a read-only transaction"); r.Status != state.CommandRefused {
		t.Fatal(r.Status)
	}
	if r := sqlFailure("ERROR 1792 (25006) at line 1: Cannot execute statement in a READ ONLY transaction."); r.Status != state.CommandRefused {
		t.Fatal(r.Status)
	}
	if r := sqlFailure(`ERROR:  relation "x" does not exist`); r.Status != state.CommandError {
		t.Fatal(r.Status)
	}
}

func TestParseMySQLXML(t *testing.T) {
	out := `<?xml version="1.0"?>
<resultset statement="SELECT 1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <row><field name="a">1</field></row>
</resultset>
<resultset statement="SELECT id, note FROM t" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <row>
	<field name="id">1</field>
	<field name="note" xsi:nil="true" />
  </row>
  <row>
	<field name="id">2</field>
	<field name="note">&lt;b&gt; &amp; NULL</field>
  </row>
</resultset>
`
	r := parseMySQLXML([]byte(out), false)
	if len(r.Columns) != 2 || r.Columns[1] != "note" || len(r.Rows) != 2 {
		t.Fatalf("%+v", r)
	}
	if r.Rows[0][1] != nil || *r.Rows[1][1] != "<b> & NULL" {
		t.Fatalf("cells %v", r.Rows)
	}
	if r := parseMySQLXML(nil, false); r.Message == "" || r.Status != state.CommandOK {
		t.Fatalf("no result set: %+v", r)
	}
}

func TestParseKeyReply(t *testing.T) {
	r := parseKeyReply("h", jsontext.Value(`["hash", -1, 2, ["f1", "v1", "f2", "v2"]]`))
	if r.Value == nil || len(r.Value.Entries) != 2 || r.Value.Entries[1][1] != "v2" || r.Value.Truncated {
		t.Fatalf("%+v", r.Value)
	}
	r = parseKeyReply("s", jsontext.Value(`["string", 5000, 70000, "abc"]`))
	if r.Value == nil || *r.Value.Text != "abc" || !r.Value.Truncated || r.Value.TTLMs != 5000 {
		t.Fatalf("%+v", r.Value)
	}
	if r := parseKeyReply("x", jsontext.Value(`["none", -2, 0, []]`)); r.Status != state.CommandError {
		t.Fatal("missing key")
	}
}

func TestEncodeResultShrinks(t *testing.T) {
	big := strings.Repeat("z", 4000)
	res := &Result{Op: "op_01J9Z8X7W6V5T4S3R2Q1P0N9T1", Status: state.CommandOK, Columns: []string{"v"}}
	for range 500 {
		res.Rows = append(res.Rows, []*string{&big})
	}
	data, err := encodeResult(res)
	if err != nil || len(data) > MaxResult || !res.Truncated {
		t.Fatalf("%d %v %v", len(data), err, res.Truncated)
	}
}
