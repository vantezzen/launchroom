package mesh

import (
	"context"
	"errors"
	"fmt"
	"io"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	serverID = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	image    = state.ImageRef("docker.io/tailscale/tailscale@" + policy.TailscaleImageDigest)
	authKey  = "hskey-auth-test-0123456789"
)

var t0 = time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)

const connectedStatus = `{
  "Version": "1.104.1",
  "BackendState": "Running",
  "TailscaleIPs": ["100.64.0.2", "fd7a:115c:a1e0::2"],
  "Health": [],
  "Self": {"TailscaleIPs": ["100.64.0.2"], "Relay": "lr"},
  "Peer": {
    "nodekey:a": {"TailscaleIPs": ["100.64.0.1", "fd7a:115c:a1e0::1"], "Online": true, "CurAddr": "192.0.2.10:41641", "Relay": "lr", "LastHandshake": "2026-10-20T11:59:30Z"},
    "nodekey:b": {"TailscaleIPs": ["100.64.0.3"], "Online": true, "CurAddr": "", "Relay": "lr", "LastHandshake": "2026-10-20T11:58:30Z"},
    "nodekey:c": {"TailscaleIPs": ["100.64.0.4"], "Online": false, "CurAddr": "", "Relay": "", "LastHandshake": "0001-01-01T00:00:00Z"},
    "nodekey:d": {"TailscaleIPs": ["not-an-ip"], "Online": true}
  }
}`

func merged(status, controlURL string) []byte {
	return fmt.Appendf(nil, `{"status":%s,"prefs":{"ControlURL":%q,"WantRunning":true}}`, status, controlURL)
}

func TestParseStatusConnected(t *testing.T) {
	st, err := ParseStatus(merged(connectedStatus, "https://dash.example.com"), t0)
	if err != nil {
		t.Fatal(err)
	}
	r := st.report
	if r.State != state.MeshConnected || r.IPv4 != "100.64.0.2" || r.IPv6 != "fd7a:115c:a1e0::2" {
		t.Fatalf("report = %+v", r)
	}
	got := make([]string, 0, len(r.Peers))
	for _, p := range r.Peers {
		got = append(got, fmt.Sprintf("%s/%v/%s/%s", p.IPv4, p.Online, p.Connection, p.Relay))
	}
	want := []string{"100.64.0.1/true/direct/lr", "100.64.0.3/true/relay/lr", "100.64.0.4/false/idle/"}
	if !slices.Equal(got, want) {
		t.Fatalf("peers = %v, want %v", got, want)
	}
	if r.Peers[2].LastHandshake != nil {
		t.Fatalf("zero handshake must be omitted")
	}
	if st.controlURL != "https://dash.example.com" || st.backend != "Running" {
		t.Fatalf("parsed = %+v", st)
	}
	// The report passes the protocol validation.
	rep := &state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: serverID, ObservedAt: t0, Seq: 1,
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: "0.1.0", Mesh: r}}
	if err := rep.Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestParseStatusNotConnected(t *testing.T) {
	for backend, want := range map[string]state.MeshState{
		"NeedsLogin":       state.MeshNeedsLogin,
		"NoState":          state.MeshNeedsLogin,
		"NeedsMachineAuth": state.MeshNeedsLogin,
		"Starting":         state.MeshStarting,
		"<script>":         state.MeshStarting,
	} {
		st, err := ParseStatus(merged(fmt.Sprintf(`{"BackendState":%q,"TailscaleIPs":null,"Peer":null,"Health":["not logged in"]}`, backend), ""), t0)
		if err != nil {
			t.Fatal(err)
		}
		if st.report.State != want || st.report.IPv4 != "" || len(st.report.Peers) != 0 {
			t.Fatalf("%s: report = %+v", backend, st.report)
		}
		if strings.Contains(st.report.Message, "<script>") {
			t.Fatalf("message echoes the raw state: %q", st.report.Message)
		}
	}
	if _, err := ParseStatus([]byte("not json"), t0); err == nil {
		t.Fatal("garbage must not parse")
	}
}

func TestNeedsLogin(t *testing.T) {
	cases := []struct {
		backend, control string
		login, reauth    bool
	}{
		{"NeedsLogin", "", true, false},
		{"NeedsLogin", "https://dash.example.com", true, false},
		{"NeedsLogin", "https://controlplane.tailscale.com", true, true},
		{"Running", "https://dash.example.com/", false, false},
		{"Running", "https://controlplane.tailscale.com", true, true},
		{"Starting", "", false, false},
		{"NeedsMachineAuth", "https://dash.example.com", false, false},
	}
	for _, c := range cases {
		login, reauth := needsLogin(c.backend, c.control, "https://dash.example.com")
		if login != c.login || reauth != c.reauth {
			t.Errorf("%s/%s: login=%v reauth=%v, want %v/%v", c.backend, c.control, login, reauth, c.login, c.reauth)
		}
	}
}

func TestUpArgsAndHostname(t *testing.T) {
	if h := Hostname(serverID); h != "lr-srv-01j9z8x7w6v5t4s3r2q1p0n9mk" {
		t.Fatalf("hostname = %s", h)
	}
	args := UpArgs(state.TailscaleSpec{LoginServer: "https://dash.example.com"}, "lr-x", false)
	for _, want := range []string{"up", "--reset", "--login-server=https://dash.example.com", "--accept-dns=false", "--accept-routes=false", "--auth-key=file:/dev/stdin", "--hostname=lr-x"} {
		if !slices.Contains(args, want) {
			t.Errorf("missing %s in %v", want, args)
		}
	}
	for _, a := range args {
		if strings.HasPrefix(a, "--advertise-tags") || a == "--force-reauth" || strings.Contains(a, "hskey") {
			t.Errorf("unexpected %s", a)
		}
	}
	args = UpArgs(state.TailscaleSpec{LoginServer: "https://controlplane.tailscale.com", Tags: []state.TailscaleTag{"tag:lr-cp", "tag:lr-edge"}}, "lr-x", true)
	if !slices.Contains(args, "--advertise-tags=tag:lr-cp,tag:lr-edge") || !slices.Contains(args, "--force-reauth") {
		t.Fatalf("args = %v", args)
	}
}

func TestContainerSpec(t *testing.T) {
	cc := ContainerSpec(serverID, image)
	h := cc.HostConfig
	if h.NetworkMode != "host" || h.Privileged || !slices.Equal(h.CapDrop, []string{"ALL"}) || !slices.Equal(h.CapAdd, []string{"NET_ADMIN", "NET_RAW"}) {
		t.Fatalf("host config = %+v", h)
	}
	if len(h.Devices) != 1 || h.Devices[0].PathOnHost != "/dev/net/tun" {
		t.Fatalf("devices = %+v", h.Devices)
	}
	if !slices.Contains(h.SecurityOpt, policy.NoNewPrivileges) || len(h.Binds) != 0 {
		t.Fatalf("security = %+v binds = %v", h.SecurityOpt, h.Binds)
	}
	for _, e := range cc.Config.Env {
		if strings.Contains(e, "AUTH") {
			t.Fatalf("no credentials in env: %s", e)
		}
	}
	if !docker.IsManaged(cc.Config.Labels, string(serverID)) || cc.Config.Labels[docker.LabelSystem] != policy.SystemTailscale {
		t.Fatalf("labels = %v", cc.Config.Labels)
	}
}

// fakeExec emulates the tailscale CLI inside the container.
type fakeExec struct {
	mu sync.Mutex
	// selfOnline, when set, is the status' Self.Online (control connection).
	selfOnline *bool
	backend    string
	control    string
	upArgv     [][]string
	upStdin    []string
	upFails    bool
	statusOK   bool
}

func (f *fakeExec) ExecStream(_ context.Context, _ string, o docker.ExecOptions) (docker.ExecResult, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if !f.statusOK {
		return docker.ExecResult{}, errors.New("socket not ready")
	}
	switch {
	case slices.Contains(o.Argv, "status"):
		s := `{"BackendState":"` + f.backend + `","TailscaleIPs":null,"Peer":null}`
		if f.backend == "Running" {
			s = connectedStatus
			if f.selfOnline != nil {
				s = strings.Replace(s, `"Self": {`, fmt.Sprintf(`"Self": {"Online": %t, `, *f.selfOnline), 1)
			}
		}
		_, _ = io.WriteString(o.Stdout, s)
	case slices.Contains(o.Argv, "prefs"):
		_, _ = fmt.Fprintf(o.Stdout, `{"ControlURL":%q}`, f.control)
	case slices.Contains(o.Argv, "up"):
		in, _ := io.ReadAll(o.Stdin)
		f.upArgv = append(f.upArgv, o.Argv)
		f.upStdin = append(f.upStdin, string(in))
		if f.upFails {
			return docker.ExecResult{ExitCode: 1, Stderr: "backend error: invalid key " + string(in)}, nil
		}
		f.backend = "Running"
		for _, a := range o.Argv {
			if v, ok := strings.CutPrefix(a, "--login-server="); ok {
				f.control = v
			}
		}
	}
	return docker.ExecResult{}, nil
}

func newManager(t *testing.T, ex *fakeExec, now *time.Time) (*Manager, *dockerfake.Fake) {
	t.Helper()
	d := dockerfake.New(string(serverID))
	d.AddImage(string(image))
	return New(Options{
		ServerID: serverID, Docker: d, Exec: ex, Policy: policy.DefaultConfig(), InsecureLoginHosts: []string{"headscale"},
		OpenAuthKey: func(s state.Sealed) (secret.Secret, error) {
			if s.Sealed == "c2VhbGVk" {
				return authKey, nil
			}
			return "", errors.New("cannot open")
		},
		Now: func() time.Time { return *now },
	}), d
}

func spec(withKey bool) state.TailscaleSpec {
	s := state.TailscaleSpec{Enabled: true, Image: image, LoginServer: "https://dash.example.com"}
	if withKey {
		s.AuthKey = &state.Sealed{Sealed: "c2VhbGVk"}
	}
	return s
}

func TestSyncLogsInWithTheKeyOnStdin(t *testing.T) {
	now := t0
	ex := &fakeExec{backend: "NeedsLogin", statusOK: true}
	m, d := newManager(t, ex, &now)

	st := m.Sync(context.Background(), spec(false))
	if st.State != state.MeshNeedsLogin || len(ex.upArgv) != 0 {
		t.Fatalf("without a key: %+v, ups %d", st, len(ex.upArgv))
	}
	if _, ok := d.Create(ContainerName); !ok {
		t.Fatal("container not created")
	}

	st = m.Sync(context.Background(), spec(true))
	if st.State != state.MeshConnected || st.IPv4 != "100.64.0.2" || m.IPv4().String() != "100.64.0.2" {
		t.Fatalf("after login: %+v", st)
	}
	if len(ex.upStdin) != 1 || ex.upStdin[0] != authKey {
		t.Fatalf("key must be passed on stdin: %v", ex.upStdin)
	}
	for _, a := range ex.upArgv[0] {
		if strings.Contains(a, authKey) {
			t.Fatalf("key in argv: %v", ex.upArgv[0])
		}
	}

	// Logged in to the same server: no second login.
	m.Sync(context.Background(), spec(true))
	if len(ex.upArgv) != 1 {
		t.Fatalf("logged in again: %d", len(ex.upArgv))
	}

	// Switching to "bring your own Tailscale" forces a re-authentication.
	byo := spec(true)
	byo.LoginServer = "https://controlplane.tailscale.com"
	m.Sync(context.Background(), byo)
	if len(ex.upArgv) != 2 || !slices.Contains(ex.upArgv[1], "--force-reauth") {
		t.Fatalf("reauth: %v", ex.upArgv)
	}
}

func TestSyncLoginFailureIsRedactedAndBackedOff(t *testing.T) {
	now := t0
	ex := &fakeExec{backend: "NeedsLogin", statusOK: true, upFails: true}
	m, _ := newManager(t, ex, &now)
	st := m.Sync(context.Background(), spec(true))
	if st.State != state.MeshError || strings.Contains(st.Message, authKey) || !strings.Contains(st.Message, "[redacted]") {
		t.Fatalf("status = %+v", st)
	}
	m.Sync(context.Background(), spec(true))
	if len(ex.upArgv) != 1 {
		t.Fatalf("retried within the backoff: %d", len(ex.upArgv))
	}
	now = now.Add(retryAfter + time.Second)
	m.Sync(context.Background(), spec(true))
	if len(ex.upArgv) != 2 {
		t.Fatalf("no retry after the backoff: %d", len(ex.upArgv))
	}
}

func TestSyncRefusesUnpinnedImagesAndPlainHTTP(t *testing.T) {
	now := t0
	ex := &fakeExec{backend: "NeedsLogin", statusOK: true}
	m, d := newManager(t, ex, &now)

	s := spec(true)
	s.Image = "docker.io/tailscale/tailscale@sha256:0000000000000000000000000000000000000000000000000000000000000000"
	if st := m.Sync(context.Background(), s); st.State != state.MeshError {
		t.Fatalf("unpinned image: %+v", st)
	}
	s = spec(true)
	s.LoginServer = "http://evil.example.com"
	if st := m.Sync(context.Background(), s); st.State != state.MeshError || !strings.Contains(st.Message, "plain http") {
		t.Fatalf("plain http: %+v", st)
	}
	if _, ok := d.Create(ContainerName); ok {
		t.Fatal("refused specs must not create the container")
	}
	// Locally allowed dev host.
	s.LoginServer = "http://headscale"
	if st := m.Sync(context.Background(), s); st.State != state.MeshConnected {
		t.Fatalf("allowed dev host: %+v", st)
	}
}

func TestSyncDisabledRemovesTheClient(t *testing.T) {
	now := t0
	ex := &fakeExec{backend: "Running", control: "https://dash.example.com", statusOK: true}
	m, d := newManager(t, ex, &now)
	if st := m.Sync(context.Background(), spec(false)); st.State != state.MeshConnected {
		t.Fatalf("status = %+v", st)
	}
	if st := m.Sync(context.Background(), state.TailscaleSpec{}); st != nil || m.Status() != nil || m.IPv4().IsValid() {
		t.Fatalf("disabled: %+v", st)
	}
	cs, _ := d.ListContainers(context.Background(), map[string]string{docker.LabelSystem: policy.SystemTailscale})
	if len(cs) != 0 {
		t.Fatalf("container left: %v", cs)
	}
}

func TestSyncReportsStartingWhileTheSocketIsNotReady(t *testing.T) {
	now := t0
	m, _ := newManager(t, &fakeExec{}, &now)
	if st := m.Sync(context.Background(), spec(true)); st.State != state.MeshStarting {
		t.Fatalf("status = %+v", st)
	}
}

// TestSyncRestartsAClientStuckWithoutControl: a running client that has no
// control connection although the coordination server answers is restarted
// after controlStuckAfter; while the server is down (or the client is
// connected) nothing is restarted.
func TestSyncRestartsAClientStuckWithoutControl(t *testing.T) {
	now := t0
	lost := false
	ex := &fakeExec{backend: "Running", control: "https://dash.example.com", statusOK: true, selfOnline: &lost}
	m, d := newManager(t, ex, &now)
	reachable := false
	m.o.Reachable = func(context.Context, state.MeshLoginURL) bool { return reachable }
	stops := 0
	restarts := func() int { // Calls drains the log
		for _, c := range d.Calls() {
			if strings.HasPrefix(c, "stop ") {
				stops++
			}
		}
		return stops
	}

	// Coordination server down: never restarted, however long.
	for range 5 {
		m.Sync(context.Background(), spec(true))
		now = now.Add(controlStuckAfter)
	}
	if restarts() != 0 {
		t.Fatal("restarted while the coordination server was down")
	}

	// Server back, client still disconnected: restarted once it has stayed so.
	reachable = true
	m.Sync(context.Background(), spec(true))
	now = now.Add(controlStuckAfter / 2)
	m.Sync(context.Background(), spec(true))
	if restarts() != 0 {
		t.Fatal("restarted too early")
	}
	now = now.Add(controlStuckAfter)
	m.Sync(context.Background(), spec(true))
	if restarts() != 1 {
		t.Fatalf("restarts = %d, want 1", stops)
	}

	// Connected: nothing more happens.
	connected := true
	ex.mu.Lock()
	ex.selfOnline = &connected
	ex.mu.Unlock()
	now = now.Add(10 * controlStuckAfter)
	m.Sync(context.Background(), spec(true))
	if restarts() != 1 {
		t.Fatal("restarted a connected client")
	}
}
