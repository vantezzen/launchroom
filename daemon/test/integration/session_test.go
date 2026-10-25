//go:build integration

package integration

import (
	"bytes"
	"context"
	"crypto/ecdh"
	"crypto/ed25519"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/json/jsontext"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/coder/websocket"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/session"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// ---- a minimal Pusher-protocol relay (what Reverb does for sessions) ----

const (
	relayKey    = "itest-key"
	relaySecret = "itest-secret"
)

type relayConn struct {
	ws       *websocket.Conn
	socketID string
	mu       sync.Mutex
	channels map[string]bool
}

func (c *relayConn) send(ctx context.Context, v any) {
	b, _ := json.Marshal(v)
	c.mu.Lock()
	defer c.mu.Unlock()
	_ = c.ws.Write(ctx, websocket.MessageText, b)
}

type fakeRelay struct {
	mu    sync.Mutex
	conns map[*relayConn]bool
	n     atomic.Int64
	srv   *httptest.Server
}

func pusherAuth(socketID, channel string) string {
	m := hmac.New(sha256.New, []byte(relaySecret))
	m.Write([]byte(socketID + ":" + channel))
	return relayKey + ":" + hex.EncodeToString(m.Sum(nil))
}

func newFakeRelay(t *testing.T) *fakeRelay {
	r := &fakeRelay{conns: map[*relayConn]bool{}}
	mux := http.NewServeMux()
	mux.HandleFunc("/app/"+relayKey, r.serveWS)
	mux.HandleFunc("/inject", func(w http.ResponseWriter, req *http.Request) {
		var ev struct{ Channel, Event, Data string }
		if err := json.NewDecoder(req.Body).Decode(&ev); err != nil {
			http.Error(w, err.Error(), 400)
			return
		}
		r.broadcast(req.Context(), nil, ev.Channel, map[string]any{"event": ev.Event, "channel": ev.Channel, "data": ev.Data})
		w.WriteHeader(204)
	})
	r.srv = httptest.NewServer(mux)
	t.Cleanup(r.srv.Close)
	return r
}

func (r *fakeRelay) broadcast(ctx context.Context, except *relayConn, channel string, msg map[string]any) {
	r.mu.Lock()
	var to []*relayConn
	for c := range r.conns {
		c.mu.Lock()
		member := c.channels[channel]
		c.mu.Unlock()
		if member && c != except {
			to = append(to, c)
		}
	}
	r.mu.Unlock()
	for _, c := range to {
		c.send(ctx, msg)
	}
}

func (r *fakeRelay) serveWS(w http.ResponseWriter, req *http.Request) {
	ws, err := websocket.Accept(w, req, &websocket.AcceptOptions{InsecureSkipVerify: true})
	if err != nil {
		return
	}
	ws.SetReadLimit(64 << 10)
	c := &relayConn{ws: ws, socketID: fmt.Sprintf("%d.%d", 100+r.n.Add(1), 7), channels: map[string]bool{}}
	r.mu.Lock()
	r.conns[c] = true
	r.mu.Unlock()
	defer func() {
		r.mu.Lock()
		delete(r.conns, c)
		r.mu.Unlock()
		_ = ws.CloseNow()
	}()
	ctx := req.Context()
	est, _ := json.Marshal(map[string]any{"socket_id": c.socketID, "activity_timeout": 30})
	c.send(ctx, map[string]any{"event": "pusher:connection_established", "data": string(est)})
	for {
		_, data, err := ws.Read(ctx)
		if err != nil {
			return
		}
		var m struct {
			Event   string          `json:"event"`
			Channel string          `json:"channel"`
			Data    json.RawMessage `json:"data"`
		}
		if json.Unmarshal(data, &m) != nil {
			return
		}
		switch {
		case m.Event == "pusher:ping":
			c.send(ctx, map[string]any{"event": "pusher:pong", "data": "{}"})
		case m.Event == "pusher:subscribe":
			var sub struct{ Auth, Channel string }
			_ = json.Unmarshal(m.Data, &sub)
			if !hmac.Equal([]byte(sub.Auth), []byte(pusherAuth(c.socketID, sub.Channel))) {
				c.send(ctx, map[string]any{"event": "pusher:subscription_error", "channel": sub.Channel, "data": `{"status":401}`})
				continue
			}
			c.mu.Lock()
			c.channels[sub.Channel] = true
			c.mu.Unlock()
			c.send(ctx, map[string]any{"event": "pusher_internal:subscription_succeeded", "channel": sub.Channel, "data": "{}"})
		case strings.HasPrefix(m.Event, "client-"):
			c.mu.Lock()
			member := c.channels[m.Channel]
			c.mu.Unlock()
			if member {
				r.broadcast(ctx, c, m.Channel, map[string]any{"event": m.Event, "channel": m.Channel, "data": m.Data})
			}
		}
	}
}

func (r *fakeRelay) inject(t *testing.T, channel, event string, data string) {
	t.Helper()
	b, _ := json.Marshal(map[string]string{"Channel": channel, "Event": event, "Data": data})
	resp, err := http.Post(r.srv.URL+"/inject", "application/json", bytes.NewReader(b)) //nolint:noctx // test
	if err != nil {
		t.Fatal(err)
	}
	_ = resp.Body.Close()
}

// authorizer plays the dashboard's signed broadcasting auth.
type authorizer struct{ channel state.DaemonChannel }

func (a authorizer) BroadcastAuth(_ context.Context, socketID state.SocketID, ch state.DaemonChannel) (state.PusherAuth, error) {
	if ch != a.channel {
		return "", fmt.Errorf("not this session's channel")
	}
	return state.PusherAuth(pusherAuth(string(socketID), string(ch))), nil
}

// ---- the browser side ----

type browser struct {
	t     *testing.T
	ws    *websocket.Conn
	codec *session.Codec
	ch    string
	in    chan session.Payload
}

func dialBrowser(ctx context.Context, t *testing.T, r *fakeRelay, key []byte, sid state.SessionID) *browser {
	t.Helper()
	ws, resp, err := websocket.Dial(ctx, strings.Replace(r.srv.URL, "http://", "ws://", 1)+"/app/"+relayKey, nil)
	if resp != nil && resp.Body != nil {
		_ = resp.Body.Close()
	}
	if err != nil {
		t.Fatal(err)
	}
	b := &browser{t: t, ws: ws, codec: session.NewCodec(key, sid, false), ch: string(state.SessionChannelFor(sid)), in: make(chan session.Payload, 256)}
	var sock string
	for sock == "" {
		_, data, err := ws.Read(ctx)
		if err != nil {
			t.Fatal(err)
		}
		var m struct{ Event, Data string }
		_ = json.Unmarshal(data, &m)
		if m.Event == "pusher:connection_established" {
			var est struct {
				SocketID string `json:"socket_id"`
			}
			_ = json.Unmarshal([]byte(m.Data), &est)
			sock = est.SocketID
		}
	}
	sub, _ := json.Marshal(map[string]any{"event": "pusher:subscribe", "data": map[string]string{"auth": pusherAuth(sock, b.ch), "channel": b.ch}})
	if err := ws.Write(ctx, websocket.MessageText, sub); err != nil {
		t.Fatal(err)
	}
	rctx := context.WithoutCancel(ctx)
	go func() {
		for {
			_, data, err := ws.Read(rctx)
			if err != nil {
				close(b.in)
				return
			}
			var m struct {
				Event string          `json:"event"`
				Data  json.RawMessage `json:"data"`
			}
			if json.Unmarshal(data, &m) != nil || m.Event != session.FrameEvent {
				continue
			}
			p, err := b.codec.Open(jsontext.Value(m.Data))
			if err != nil {
				t.Errorf("browser: bad frame from the daemon: %v", err)
				continue
			}
			b.in <- p
		}
	}()
	t.Cleanup(func() { _ = ws.CloseNow() })
	return b
}

func (b *browser) send(ctx context.Context, p session.Payload) {
	b.t.Helper()
	f, err := b.codec.Seal(p)
	if err != nil {
		b.t.Fatal(err)
	}
	msg, _ := json.Marshal(map[string]any{"event": session.FrameEvent, "channel": b.ch, "data": f})
	if err := b.ws.Write(ctx, websocket.MessageText, msg); err != nil {
		b.t.Fatal(err)
	}
}

// await returns the next payload of type typ (others are passed to seen).
func (b *browser) await(typ string, seen func(session.Payload)) session.Payload {
	b.t.Helper()
	timeout := time.After(60 * time.Second)
	for {
		select {
		case p, ok := <-b.in:
			if !ok {
				b.t.Fatalf("relay closed while waiting for %s", typ)
			}
			if p.T == typ {
				return p
			}
			if seen != nil {
				seen(p)
			}
		case <-timeout:
			b.t.Fatalf("no %s frame", typ)
		}
	}
}

// result reassembles one data result.
func (b *browser) result(op state.OpID) map[string]any {
	b.t.Helper()
	var buf []byte
	for {
		p := b.await(session.TResult, nil)
		if p.Op != op {
			b.t.Fatalf("result of %s while waiting for %s", p.Op, op)
		}
		d, err := session.DecodeData(p.D)
		if err != nil {
			b.t.Fatal(err)
		}
		buf = append(buf, d...)
		if p.Part == p.Parts-1 {
			break
		}
	}
	var out map[string]any
	if err := json.Unmarshal(buf, &out); err != nil {
		b.t.Fatalf("result JSON: %v", err)
	}
	return out
}

// ---- the test ----

type sessionEnv struct {
	engine   *docker.Engine
	serverID state.ServerID
	key      *ecdh.PrivateKey
	relay    *fakeRelay
}

func (s *sessionEnv) start(ctx context.Context, t *testing.T, image string, env []string, cmd []string) state.WorkloadID {
	t.Helper()
	return s.startAs(ctx, t, image, env, cmd, "")
}

// startAs starts a workload container running as user (empty: the image's).
func (s *sessionEnv) startAs(ctx context.Context, t *testing.T, image string, env []string, cmd []string, user string) state.WorkloadID {
	t.Helper()
	if err := s.engine.PullImage(ctx, image); err != nil {
		t.Fatalf("pull %s: %v", image, err)
	}
	wl := state.WorkloadID(randomID("wl"))
	labels := docker.ManagedLabels(string(s.serverID))
	labels[docker.LabelWorkload] = string(wl)
	id, err := fixtureEngine(t, s.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name:       docker.ContainerName(wl),
		Config:     &container.Config{Image: image, Env: env, Labels: labels, Cmd: cmd, User: user},
		HostConfig: &container.HostConfig{},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := s.engine.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
	return wl
}

type logSink struct{ tasks.Client }

func (logSink) ShipLogs(context.Context, *state.LogBatch) error { return nil }

func (s *sessionEnv) runSession(ctx context.Context, t *testing.T, spec *state.SessionSpec, wlEnv map[string]secret.Secret, trusted identity.KeySet) (key []byte, done chan tasks.Result, errc chan error) {
	t.Helper()
	key = make([]byte, 32)
	_, _ = rand.Read(key)
	task := state.TaskID(randomID("tsk"))
	sealedKey, err := sealed.SealJSON(state.TaskToken{ServerID: s.serverID, TaskID: task, Purpose: state.PurposeSession, Token: secret.Secret(base64.RawURLEncoding.EncodeToString(key))}, s.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	spec.Key = sealedKey
	spec.Channel = state.SessionChannelFor(spec.Session)
	if spec.IdleTimeout == "" {
		spec.IdleTimeout = "5m"
	}
	if err := spec.Validate(); err != nil {
		t.Fatal(err)
	}
	h := session.Handler(session.Options{
		ServerID: s.serverID, SealingKey: s.key, Auth: authorizer{channel: spec.Channel},
		Nudge: func(context.Context) (*state.NudgeConfig, error) {
			return &state.NudgeConfig{Key: relayKey, URL: state.WebSocketURL(strings.Replace(s.relay.srv.URL, "http://", "ws://", 1))}, nil
		},
		Trusted:        func() session.Verifier { return trusted },
		Docker:         s.engine,
		WorkloadEnv:    func(state.WorkloadID) (map[string]secret.Secret, error) { return wlEnv, nil },
		InDesiredState: func(id state.WorkloadID) bool { return id == spec.Workload },
		Logs:           logSink{},
		HelloWait:      30 * time.Second,
	})
	done, errc = make(chan tasks.Result, 1), make(chan error, 1)
	go func() {
		res, err := h(ctx, state.Task{ID: task, Type: state.TaskSession, Attempt: 1, Deadline: time.Now().Add(10 * time.Minute), Spec: spec})
		done <- res
		errc <- err
	}()
	return key, done, errc
}

func TestInteractiveSessions(t *testing.T) {
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })
	s := &sessionEnv{serverID: state.ServerID(randomID("srv")), relay: newFakeRelay(t)}
	s.key, err = ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	s.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(s.serverID), UserAgent: "launchroom-itest"})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()

	t.Run("console", func(t *testing.T) {
		wl := s.start(ctx, t, busyboxImage, nil, []string{"sleep", "3600"})
		sid := state.SessionID(randomID("ses"))
		spec := &state.SessionSpec{Session: sid, Kind: state.SessionConsole, Workload: wl, Shell: state.ShellSh, Cols: 80, Rows: 24}
		key, done, errc := s.runSession(ctx, t, spec, nil, nil)
		b := dialBrowser(ctx, t, s.relay, key, sid)
		b.await(session.TReady, nil)
		b.send(ctx, session.Payload{T: session.THello, Cols: 100, Rows: 30})
		if p := b.await(session.TStarted, nil); p.Shell != "/bin/sh" {
			t.Fatalf("shell %q", p.Shell)
		}
		var out strings.Builder
		collect := func(p session.Payload) {
			if p.T == session.TOut {
				d, _ := session.DecodeData(p.D)
				out.Write(d)
			}
		}
		b.send(ctx, session.Payload{T: session.TIn, D: session.EncodeData([]byte("echo hi-$((40+2)) && stty size\n"))})
		deadline := time.Now().Add(30 * time.Second)
		for !strings.Contains(out.String(), "hi-42") || !strings.Contains(out.String(), "30 100") {
			if time.Now().After(deadline) {
				t.Fatalf("no output; got %q", out.String())
			}
			collect(b.await(session.TOut, nil))
		}
		b.send(ctx, session.Payload{T: session.TResize, Cols: 120, Rows: 40})
		time.Sleep(200 * time.Millisecond)
		b.send(ctx, session.Payload{T: session.TIn, D: session.EncodeData([]byte("stty size; id -u; exit 3\n"))})
		exit := b.await(session.TExit, collect)
		if exit.Code == nil || *exit.Code != 3 {
			t.Fatalf("exit %+v", exit)
		}
		if !strings.Contains(out.String(), "40 120") {
			t.Errorf("resize not applied: %q", out.String())
		}
		if end := b.await(session.TEnd, nil); end.Reason != string(state.EndExited) {
			t.Fatalf("end %q", end.Reason)
		}
		res := <-done
		if err := <-errc; err != nil {
			t.Fatal(err)
		}
		if res.Session == nil || res.Session.EndReason != state.EndExited || res.Session.ExitCode == nil || *res.Session.ExitCode != 3 || res.Session.BytesIn == 0 || res.Session.BytesOut == 0 {
			t.Fatalf("result %+v", res.Session)
		}
	})

	// An image without bash, running as a non-root user (Umami: node:alpine
	// as "nextjs"): Automatic falls back to ash/sh, as that user.
	t.Run("console without bash as a non-root user", func(t *testing.T) {
		wl := s.startAs(ctx, t, busyboxImage, nil, []string{"sleep", "3600"}, "65534:65534")
		sid := state.SessionID(randomID("ses"))
		spec := &state.SessionSpec{Session: sid, Kind: state.SessionConsole, Workload: wl, Cols: 80, Rows: 24}
		key, done, errc := s.runSession(ctx, t, spec, nil, nil)
		b := dialBrowser(ctx, t, s.relay, key, sid)
		b.await(session.TReady, nil)
		b.send(ctx, session.Payload{T: session.THello, Cols: 80, Rows: 24})
		started := b.await(session.TStarted, nil)
		if started.Shell != "/bin/ash" && started.Shell != "/bin/sh" {
			t.Fatalf("shell %q", started.Shell)
		}
		var out strings.Builder
		collect := func(p session.Payload) {
			if p.T == session.TOut {
				d, _ := session.DecodeData(p.D)
				out.Write(d)
			}
		}
		b.send(ctx, session.Payload{T: session.TIn, D: session.EncodeData([]byte("echo uid-$(id -u); exit 0\n"))})
		b.await(session.TExit, collect)
		if !strings.Contains(out.String(), "uid-65534") {
			t.Fatalf("not the container's user: %q", out.String())
		}
		b.await(session.TEnd, nil)
		<-done
		if err := <-errc; err != nil {
			t.Fatal(err)
		}
	})

	t.Run("console refuses a shell the image lacks", func(t *testing.T) {
		wl := s.start(ctx, t, busyboxImage, nil, []string{"sleep", "3600"})
		spec := &state.SessionSpec{Session: state.SessionID(randomID("ses")), Kind: state.SessionConsole, Workload: wl, Shell: "/bin/bash"}
		_, done, errc := s.runSession(ctx, t, spec, nil, nil)
		res := <-done
		if err := <-errc; err == nil || !strings.Contains(err.Error(), "no /bin/bash") {
			t.Fatalf("missing shell: %v %+v", err, res.Session)
		}
	})

	t.Run("data postgres", func(t *testing.T) {
		pgEnv := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "app", "POSTGRES_PASSWORD": "pw-session-123456"}
		var kv []string
		for k, v := range pgEnv {
			kv = append(kv, k+"="+v.Reveal())
		}
		wl := s.start(ctx, t, postgresImage, kv, nil)
		waitPG(ctx, t, s.engine, wl)

		pub, priv, _ := ed25519.GenerateKey(rand.Reader)
		trusted := identity.KeySet{"cp1": pub}
		sid := state.SessionID(randomID("ses"))
		spec := &state.SessionSpec{Session: sid, Kind: state.SessionData, Workload: wl, Engine: state.EnginePostgres}
		key, done, errc := s.runSession(ctx, t, spec, pgEnv, trusted)
		b := dialBrowser(ctx, t, s.relay, key, sid)
		b.await(session.TReady, nil)
		b.send(ctx, session.Payload{T: session.THello})
		b.await(session.TStarted, nil)

		command := func(c state.SessionCommand) map[string]any {
			t.Helper()
			c.TypeMeta = state.NewTypeMeta(state.KindSessionCommand)
			c.Session, c.Op, c.IssuedAt = sid, state.OpID(randomID("op")), time.Now().UTC()
			payload, err := json.Marshal(&c)
			if err != nil {
				t.Fatal(err)
			}
			sig, _ := identity.SignResponse("cp1", priv, payload)
			data, _ := json.Marshal(map[string]string{"payload": string(payload), "signature": sig})
			event := state.SessionEventCommand
			if c.Command == state.CommandEnd {
				event = state.SessionEventEnd
			}
			s.relay.inject(t, string(spec.Channel), event, string(data))
			if c.Command == state.CommandEnd {
				return nil
			}
			return b.result(c.Op)
		}

		r := command(state.SessionCommand{Command: state.CommandQuery, Statement: "SELECT 1 AS one, NULL AS nothing"})
		if r["status"] != "ok" || fmt.Sprint(r["columns"]) != "[one nothing]" || fmt.Sprint(r["rows"]) != "[[1 <nil>]]" {
			t.Fatalf("select: %v", r)
		}
		r = command(state.SessionCommand{Command: state.CommandQuery, Statement: "CREATE TABLE notes (id int, body text)"})
		if r["status"] != "refused" {
			t.Fatalf("read-only CREATE: %v", r)
		}
		r = command(state.SessionCommand{Command: state.CommandQuery, AllowWrites: true, Statement: "CREATE TABLE notes (id int, body text); INSERT INTO notes VALUES (1, 'a'), (2, 'b, \"quoted\"')"})
		if r["status"] != "ok" || fmt.Sprint(r["affected"]) != "2" {
			t.Fatalf("write: %v", r)
		}
		r = command(state.SessionCommand{Command: state.CommandQuery, Statement: "INSERT INTO notes VALUES (3, 'c')"})
		if r["status"] != "refused" || !strings.Contains(fmt.Sprint(r["message"]), "read-only") {
			t.Fatalf("read-only INSERT: %v", r)
		}
		r = command(state.SessionCommand{Command: state.CommandRows, Table: &state.TableRef{Name: "notes"}, PageSize: 1, Page: 1})
		if fmt.Sprint(r["rows"]) != `[[2 b, "quoted"]]` {
			t.Fatalf("rows: %v", r)
		}
		r = command(state.SessionCommand{Command: state.CommandSchema})
		if !strings.Contains(fmt.Sprint(r["schema"]), "notes") {
			t.Fatalf("schema: %v", r)
		}
		r = command(state.SessionCommand{Command: state.CommandQuery, Statement: "SELECT generate_series(1, 2000) AS n"})
		if r["truncated"] != true || len(r["rows"].([]any)) != session.MaxRows {
			t.Fatalf("row cap: truncated=%v rows=%d", r["truncated"], len(r["rows"].([]any)))
		}
		r = command(state.SessionCommand{Command: state.CommandQuery, Statement: "SELEC nope"})
		if r["status"] != "error" || strings.Contains(fmt.Sprint(r["message"]), "pw-session") {
			t.Fatalf("error: %v", r)
		}
		command(state.SessionCommand{Command: state.CommandEnd, Reason: "test over"})
		if end := b.await(session.TEnd, nil); end.Reason != string(state.EndEnded) {
			t.Fatalf("end %q", end.Reason)
		}
		res := <-done
		if err := <-errc; err != nil {
			t.Fatal(err)
		}
		if res.Session.EndReason != state.EndEnded || len(res.Session.Commands) != 8 {
			t.Fatalf("result %+v", res.Session)
		}
	})
}

func waitPG(ctx context.Context, t *testing.T, e *docker.Engine, wl state.WorkloadID) {
	t.Helper()
	cs, err := e.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if err != nil || len(cs) != 1 {
		t.Fatalf("container: %v", err)
	}
	deadline := time.Now().Add(2 * time.Minute)
	for {
		res, err := e.ExecStream(ctx, cs[0].ID, docker.ExecOptions{
			Argv: []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-tAc", "SELECT 1"}, Env: []string{"PGPASSWORD=pw-session-123456"},
		})
		if err == nil && res.ExitCode == 0 {
			return
		}
		if time.Now().After(deadline) {
			t.Fatal("postgres did not become ready")
		}
		time.Sleep(time.Second)
	}
}
