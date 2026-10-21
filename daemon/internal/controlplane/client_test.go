package controlplane

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	serverID = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	kid      = "cp-1"
	taskID   = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0")
)

// reply describes how the fake dashboard answers.
type reply struct {
	status int
	body   string
	// sign: "" = sign with the CP key, "none" = unsigned, "other" = sign with an unknown key,
	// "garbage" = malformed header.
	sign string
}

type fakeCP struct {
	t         *testing.T
	daemonPub ed25519.PublicKey
	cpKey     ed25519.PrivateKey
	other     ed25519.PrivateKey

	mu       sync.Mutex
	handle   func(r *http.Request, body []byte) reply
	requests []recorded
}

type recorded struct {
	method, path, query string
	header              http.Header
	body                string
	keyID               string
}

func newFakeCP(t *testing.T, daemonPub ed25519.PublicKey) (*fakeCP, *httptest.Server) {
	t.Helper()
	_, cpKey, _ := ed25519.GenerateKey(rand.Reader)
	_, other, _ := ed25519.GenerateKey(rand.Reader)
	f := &fakeCP{t: t, daemonPub: daemonPub, cpKey: cpKey, other: other}
	srv := httptest.NewServer(http.HandlerFunc(f.serve))
	t.Cleanup(srv.Close)
	return f, srv
}

func (f *fakeCP) pub() ed25519.PublicKey {
	p, _ := f.cpKey.Public().(ed25519.PublicKey)
	return p
}

func (f *fakeCP) serve(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	vr, err := identity.VerifyRequest(r, body, identity.RequestVerifyOptions{
		LookupKey: func(string) (ed25519.PublicKey, error) { return f.daemonPub, nil },
	})
	if err != nil {
		f.t.Errorf("request %s %s not correctly signed: %v", r.Method, r.URL.Path, err)
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	if r.Header.Get(ProtocolVersionsHeader) != "v1" {
		f.t.Errorf("missing protocol versions header")
	}
	f.mu.Lock()
	f.requests = append(f.requests, recorded{r.Method, r.URL.Path, r.URL.RawQuery, r.Header.Clone(), string(body), vr.KeyID})
	h := f.handle
	f.mu.Unlock()

	rep := h(r, body)
	switch rep.sign {
	case "":
		hdr, _ := identity.SignResponse(kid, f.cpKey, []byte(rep.body))
		w.Header().Set(identity.ResponseSignatureHeader, hdr)
	case "other":
		hdr, _ := identity.SignResponse(kid, f.other, []byte(rep.body))
		w.Header().Set(identity.ResponseSignatureHeader, hdr)
	case "garbage":
		w.Header().Set(identity.ResponseSignatureHeader, "kid=cp-1, sig=nope")
	}
	w.WriteHeader(rep.status)
	_, _ = io.WriteString(w, rep.body)
}

func (f *fakeCP) set(h func(r *http.Request, body []byte) reply) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.handle = h
}

func (f *fakeCP) last() recorded {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.requests) == 0 {
		f.t.Fatal("no request recorded")
	}
	return f.requests[len(f.requests)-1]
}

func clientFor(t *testing.T, id *identity.Identity, srvURL, keyID string, trusted identity.KeySet) *Client {
	t.Helper()
	signer, err := id.NewSigner(keyID)
	if err != nil {
		t.Fatal(err)
	}
	u, _ := url.Parse(srvURL)
	c, err := New(Options{BaseURL: u, Signer: signer, Trusted: trusted, DaemonVersion: "0.1.0", Timeout: 5 * time.Second})
	if err != nil {
		t.Fatal(err)
	}
	return c
}

func fixture(t *testing.T, name string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("../../../protocol/fixtures/v1", name)) //nolint:gosec // test fixture
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func enrollRequest(id *identity.Identity) *state.EnrollRequest {
	return &state.EnrollRequest{
		TypeMeta:         state.NewTypeMeta(state.KindEnrollRequest),
		Token:            secret.Secret("lr_jt_Zm9vYmFyYmF6cXV4cXV1eA"),
		SigningKey:       state.Ed25519Key(identity.FormatPublicKey(id.SigningPublicKey())),
		SealingKey:       state.X25519Key(identity.FormatSealingKey(id.SealingPublicKey())),
		DaemonVersion:    "0.1.0",
		ProtocolVersions: []string{"v1"},
		Host:             state.HostFacts{Hostname: "web-1", OS: "linux", Arch: "arm64", CPUs: 2},
	}
}

func enrollResponseBody(keys map[string]ed25519.PublicKey) string {
	var parts []string
	for k, p := range keys {
		parts = append(parts, `"`+k+`":"`+identity.FormatPublicKey(p)+`"`)
	}
	return `{"apiVersion":"launchroom.vantezzen.io/v1","kind":"EnrollResponse","serverId":"` + string(serverID) +
		`","status":"pending_approval","controlPlaneKeys":{` + strings.Join(parts, ",") +
		`},"registry":{"address":"127.0.0.1:5000"},"pollIntervals":{"state":"30s","tasks":"10s"}}`
}

func TestEnroll(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, identity.EnrollKeyID, nil)
	_, otherPub := func() (ed25519.PrivateKey, ed25519.PublicKey) {
		pub, k, _ := ed25519.GenerateKey(rand.Reader)
		return k, pub
	}()

	t.Run("ok", func(t *testing.T) {
		f.set(func(*http.Request, []byte) reply {
			return reply{status: 201, body: enrollResponseBody(map[string]ed25519.PublicKey{kid: f.pub(), "cp-next": otherPub})}
		})
		res, err := c.Enroll(t.Context(), enrollRequest(id), f.pub())
		if err != nil {
			t.Fatal(err)
		}
		if res.Response.ServerID != serverID || len(res.Trusted) != 2 {
			t.Fatalf("result = %+v", res)
		}
		got := f.last()
		if got.keyID != identity.EnrollKeyID || got.path != "/daemon/v1/enroll" {
			t.Fatalf("request = %+v", got)
		}
		if !strings.Contains(got.body, `"token":"lr_jt_`) {
			t.Fatal("token not sent")
		}
	})

	cases := map[string]struct {
		reply reply
		check func(error) bool
	}{
		"signed by another key":      {reply{status: 200, body: enrollResponseBody(map[string]ed25519.PublicKey{kid: f.pub()}), sign: "other"}, isSig},
		"unsigned":                   {reply{status: 200, body: enrollResponseBody(map[string]ed25519.PublicKey{kid: f.pub()}), sign: "none"}, isSig},
		"key set without pinned key": {reply{status: 200, body: enrollResponseBody(map[string]ed25519.PublicKey{kid: otherPub})}, isSig},
		"pinned key under other kid": {reply{status: 200, body: enrollResponseBody(map[string]ed25519.PublicKey{"x": f.pub()})}, isSig},
		"invalid document":           {reply{status: 200, body: `{"kind":"EnrollResponse"}`}, isDecode},
		"token rejected":             {reply{status: 403, body: `{"message":"token used"}`, sign: "none"}, func(err error) bool { return isAPI(err, 403) && !IsRetryable(err) }},
		"server error":               {reply{status: 502, body: "bad gateway", sign: "none"}, func(err error) bool { return isAPI(err, 502) && IsRetryable(err) }},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			f.set(func(*http.Request, []byte) reply { return tc.reply })
			_, err := c.Enroll(t.Context(), enrollRequest(id), f.pub())
			if err == nil || !tc.check(err) {
				t.Fatalf("err = %v (%T)", err, err)
			}
		})
	}
}

func isSig(err error) bool    { var e *SignatureError; return errors.As(err, &e) }
func isDecode(err error) bool { var e *DecodeError; return errors.As(err, &e) }
func isAPI(err error, status int) bool {
	var e *APIError
	return errors.As(err, &e) && e.Status == status
}

func TestFetchState(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, string(serverID), identity.KeySet{kid: f.pub()})
	stateDoc := fixture(t, "node-state.web-postgres.json")

	f.set(func(r *http.Request, _ []byte) reply {
		if r.URL.Query().Get("have") == "1842" {
			return reply{status: 304, sign: "none"}
		}
		return reply{status: 200, body: stateDoc}
	})
	ss, modified, err := c.FetchState(t.Context(), serverID, 0)
	if err != nil || !modified {
		t.Fatalf("modified=%v err=%v", modified, err)
	}
	if ss.State.Version != 1842 || string(ss.Raw) != stateDoc || ss.KeyID != kid {
		t.Fatalf("state = %+v", ss.State)
	}
	if got := f.last(); got.method != http.MethodGet || got.path != "/daemon/v1/state" || got.query != "have=0" || got.keyID != string(serverID) {
		t.Fatalf("request = %+v", got)
	}

	if ss, modified, err := c.FetchState(t.Context(), serverID, 1842); err != nil || modified || ss != nil {
		t.Fatalf("304: ss=%v modified=%v err=%v", ss, modified, err)
	}

	bad := map[string]struct {
		reply reply
		check func(error) bool
	}{
		"unsigned":         {reply{status: 200, body: stateDoc, sign: "none"}, isSig},
		"unknown key":      {reply{status: 200, body: stateDoc, sign: "other"}, isSig},
		"malformed header": {reply{status: 200, body: stateDoc, sign: "garbage"}, isSig},
		"signed garbage":   {reply{status: 200, body: `{"kind":"NodeState"}`}, isDecode},
		"other server":     {reply{status: 200, body: strings.Replace(stateDoc, string(serverID), "srv_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ", 1)}, isDecode},
		"pending approval": {reply{status: 403, body: "not approved", sign: "none"}, func(err error) bool { return isAPI(err, 403) }},
	}
	for name, tc := range bad {
		t.Run(name, func(t *testing.T) {
			f.set(func(*http.Request, []byte) reply { return tc.reply })
			_, _, err := c.FetchState(t.Context(), serverID, 0)
			if err == nil || !tc.check(err) {
				t.Fatalf("err = %v (%T)", err, err)
			}
		})
	}
}

func TestEmptyTrustSetFailsClosed(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, string(serverID), nil)
	f.set(func(*http.Request, []byte) reply {
		return reply{status: 200, body: fixture(t, "node-state.web-postgres.json")}
	})
	if _, _, err := c.FetchState(t.Context(), serverID, 0); !isSig(err) {
		t.Fatalf("err = %v", err)
	}
}

func TestTasksReportsLogs(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, string(serverID), identity.KeySet{kid: f.pub()})
	lease := fixture(t, "task-lease.build.json")

	// Lease.
	f.set(func(*http.Request, []byte) reply { return reply{status: 200, body: lease} })
	tasks, err := c.LeaseTasks(t.Context(), &state.LeaseRequest{TypeMeta: state.NewTypeMeta(state.KindLeaseRequest), Max: 1, Types: []state.TaskType{state.TaskBuild}})
	if err != nil {
		t.Fatal(err)
	}
	if len(tasks) != 1 || tasks[0].Type != state.TaskBuild {
		t.Fatalf("tasks = %+v", tasks)
	}
	if got := f.last(); got.path != "/daemon/v1/tasks/lease" || !strings.Contains(got.body, `"kind":"LeaseRequest"`) {
		t.Fatalf("lease request = %+v", got)
	}
	f.set(func(*http.Request, []byte) reply { return reply{status: 200, body: lease, sign: "none"} })
	if _, err := c.LeaseTasks(t.Context(), &state.LeaseRequest{TypeMeta: state.NewTypeMeta(state.KindLeaseRequest), Max: 1, Types: []state.TaskType{state.TaskBuild}}); !isSig(err) {
		t.Fatalf("unsigned lease accepted: %v", err)
	}

	// Heartbeat.
	f.set(func(*http.Request, []byte) reply {
		return reply{status: 200, body: `{"apiVersion":"launchroom.vantezzen.io/v1","kind":"TaskHeartbeatResult","leaseExpiresAt":"2026-10-17T12:02:00Z","cancel":true}`}
	})
	hb, err := c.Heartbeat(t.Context(), taskID, &state.TaskHeartbeat{TypeMeta: state.NewTypeMeta(state.KindHeartbeat), Attempt: 1})
	if err != nil || !hb.Cancel {
		t.Fatalf("heartbeat = %+v, %v", hb, err)
	}
	if got := f.last(); got.path != "/daemon/v1/tasks/"+string(taskID)+"/heartbeat" {
		t.Fatalf("heartbeat path = %s", got.path)
	}
	if _, err := c.Heartbeat(t.Context(), "tsk_../../state", &state.TaskHeartbeat{TypeMeta: state.NewTypeMeta(state.KindHeartbeat), Attempt: 1}); err == nil {
		t.Fatal("invalid task id accepted into path")
	}

	// Complete (no body to verify, idempotency key set).
	f.set(func(*http.Request, []byte) reply { return reply{status: 204, sign: "none"} })
	if err := c.Complete(t.Context(), taskID, &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 2, Outcome: state.OutcomeFailed, Error: "build failed"}); err != nil {
		t.Fatal(err)
	}
	if got := f.last(); got.header.Get("Idempotency-Key") != string(taskID)+":2" {
		t.Fatalf("idempotency key = %q", got.header.Get("Idempotency-Key"))
	}

	// Report.
	now := time.Now().UTC()
	rep := &state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: serverID, ObservedAt: now, Seq: 1, Full: true,
		Workloads: []state.WorkloadStatus{{ID: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7", Phase: state.PhaseHealthy, ObservedAt: now}}}
	if err := c.Report(t.Context(), rep); err != nil {
		t.Fatal(err)
	}
	if got := f.last(); got.path != "/daemon/v1/reports" || !strings.Contains(got.body, `"phase":"healthy"`) {
		t.Fatalf("report request = %+v", got)
	}

	// Logs, with a retryable failure.
	batch := &state.LogBatch{TypeMeta: state.NewTypeMeta(state.KindLogBatch), Chunks: []state.LogChunk{{
		Source: state.LogSource{Kind: state.LogSourceTask, ID: string(taskID)}, Seq: 1,
		Lines: []state.LogLine{{Time: now, Stream: state.StreamStdout, Text: "step 1/3"}},
	}}}
	if err := c.ShipLogs(t.Context(), batch); err != nil {
		t.Fatal(err)
	}
	f.set(func(*http.Request, []byte) reply { return reply{status: 503, body: "\x1b[31mdown\x00", sign: "none"} })
	err = c.ShipLogs(t.Context(), batch)
	if !isAPI(err, 503) || !IsRetryable(err) {
		t.Fatalf("err = %v", err)
	}
	if strings.ContainsAny(err.Error(), "\x1b\x00") {
		t.Fatal("unsanitized error body")
	}

	// Invalid documents are never sent.
	if err := c.Report(t.Context(), &state.Report{}); err == nil {
		t.Fatal("invalid report sent")
	}
}

func TestNoRedirectsAndBasePath(t *testing.T) {
	id, _ := identity.Generate()
	var paths []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.Path)
		http.Redirect(w, r, "https://evil.example/daemon/v1/state", http.StatusFound)
	}))
	defer srv.Close()
	c := clientFor(t, id, srv.URL+"/launchroom/", string(serverID), identity.KeySet{kid: id.SigningPublicKey()})
	_, _, err := c.FetchState(t.Context(), serverID, 0)
	if !isAPI(err, http.StatusFound) {
		t.Fatalf("err = %v", err)
	}
	if len(paths) != 1 || paths[0] != "/launchroom/daemon/v1/state" {
		t.Fatalf("paths = %v", paths)
	}
}

func TestResponseSizeLimit(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, string(serverID), identity.KeySet{kid: f.pub()})
	big := strings.Repeat("x", maxResponseBytes+10)
	f.set(func(*http.Request, []byte) reply { return reply{status: 200, body: big} })
	if _, err := c.Heartbeat(t.Context(), taskID, &state.TaskHeartbeat{TypeMeta: state.NewTypeMeta(state.KindHeartbeat), Attempt: 1}); err == nil {
		t.Fatal("oversized response accepted")
	}
}

func TestSanitize(t *testing.T) {
	got := sanitize([]byte("ok\x00\x1b[0m" + base64.StdEncoding.EncodeToString(make([]byte, 400))))
	if strings.ContainsAny(got, "\x00\x1b") || len(got) > maxErrorSnippet {
		t.Fatalf("sanitize = %q", got)
	}
}

func TestRegistryToken(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, string(serverID), identity.KeySet{kid: f.pub()})
	req := &state.RegistryTokenRequest{
		TypeMeta: state.NewTypeMeta(state.KindRegistryTokenRequest),
		Scopes:   []state.RegistryScope{{Repository: "apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2", Actions: []state.RegistryAction{state.RegistryPull, state.RegistryPush}}},
		Task:     taskID,
	}
	body := func(actions string) string {
		return `{"apiVersion":"launchroom.vantezzen.io/v1","kind":"RegistryToken","token":"eyJhbGciOiJFUzI1NiJ9.eyJzdWIiOiJ4In0.c2ln",` +
			`"expiresAt":"2026-10-17T12:05:00Z","scopes":[{"repository":"apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2","actions":[` + actions + `]}]}`
	}
	f.set(func(*http.Request, []byte) reply { return reply{status: 200, body: body(`"pull","push"`)} })
	tok, err := c.RegistryToken(t.Context(), req)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(tok.Token.Reveal(), "eyJ") || tok.ExpiresAt.IsZero() {
		t.Fatalf("token = %+v", tok)
	}
	if got := f.last(); got.path != "/daemon/v1/registry-token" || !strings.Contains(got.body, `"task":"`+string(taskID)+`"`) {
		t.Fatalf("request = %+v", got)
	}
	// A narrower grant than requested is refused by the daemon too.
	f.set(func(*http.Request, []byte) reply { return reply{status: 200, body: body(`"pull"`)} })
	if _, err := c.RegistryToken(t.Context(), req); err == nil {
		t.Fatal("narrower token accepted")
	}
	f.set(func(*http.Request, []byte) reply {
		return reply{status: 200, body: body(`"pull","push"`), sign: "none"}
	})
	if _, err := c.RegistryToken(t.Context(), req); !isSig(err) {
		t.Fatalf("unsigned token accepted: %v", err)
	}
	f.set(func(*http.Request, []byte) reply {
		return reply{status: 403, body: `{"error":"forbidden"}`, sign: "none"}
	})
	var api *APIError
	if _, err := c.RegistryToken(t.Context(), req); !errors.As(err, &api) || api.Status != 403 {
		t.Fatalf("403 not surfaced: %v", err)
	}
}
