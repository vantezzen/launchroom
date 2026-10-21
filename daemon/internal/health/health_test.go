package health

import (
	"context"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestParseStatusRange(t *testing.T) {
	cases := map[state.StatusRange][2]int{
		"200": {200, 200}, "2xx": {200, 299}, "2xx-3xx": {200, 399}, "200-399": {200, 399}, "204-2xx": {204, 299},
	}
	for in, want := range cases {
		lo, hi, err := ParseStatusRange(in)
		if err != nil || lo != want[0] || hi != want[1] {
			t.Errorf("%s: %d-%d %v", in, lo, hi, err)
		}
	}
	for _, bad := range []state.StatusRange{"", "600", "3xx-2xx", "abc"} {
		if _, _, err := ParseStatusRange(bad); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
}

func spec(start string, threshold int) state.HealthSpec {
	return state.HealthSpec{Type: state.HealthHTTP, Path: "/", StartPeriod: state.Duration(start), Interval: "1s", Timeout: "1s", FailureThreshold: threshold}
}

func TestNextStateMachine(t *testing.T) {
	t0 := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	sp := spec("30s", 3)
	fail := errors.New("connection refused")

	// Failures inside the start period don't count.
	st := Next(Status{}, fail, t0.Add(5*time.Second), t0, sp)
	st = Next(st, fail, t0.Add(10*time.Second), t0, sp)
	if st.State != state.ProbeStarting || st.ConsecutiveFailures != 0 || st.LastError != "connection refused" {
		t.Fatalf("%+v", st)
	}
	// After the start period they do.
	for i := range 3 {
		st = Next(st, fail, t0.Add(time.Duration(31+i)*time.Second), t0, sp)
	}
	if st.State != state.ProbeFailing || st.ConsecutiveFailures != 3 {
		t.Fatalf("%+v", st)
	}
	// One success recovers.
	st = Next(st, nil, t0.Add(40*time.Second), t0, sp)
	if st.State != state.ProbePassing || st.ConsecutiveFailures != 0 || st.LastError != "" {
		t.Fatalf("%+v", st)
	}
	// A success ends the start period: the next failures count immediately.
	st = Next(Status{}, nil, t0.Add(time.Second), t0, sp)
	st = Next(st, fail, t0.Add(2*time.Second), t0, sp)
	if st.State != state.ProbePassing || st.ConsecutiveFailures != 1 {
		t.Fatalf("%+v", st)
	}
	st = Next(st, fail, t0.Add(3*time.Second), t0, sp)
	st = Next(st, fail, t0.Add(4*time.Second), t0, sp)
	if st.State != state.ProbeFailing {
		t.Fatalf("%+v", st)
	}
}

func addrOf(t *testing.T, rawURL string) netip.AddrPort {
	t.Helper()
	ap, err := netip.ParseAddrPort(strings.TrimPrefix(rawURL, "http://"))
	if err != nil {
		t.Fatal(err)
	}
	return ap
}

func TestProbeHTTP(t *testing.T) {
	var status atomic.Int32
	status.Store(200)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/up" {
			w.WriteHeader(404)
			return
		}
		if status.Load() == 302 {
			http.Redirect(w, r, "http://169.254.169.254/", http.StatusFound)
			return
		}
		w.WriteHeader(int(status.Load()))
	}))
	defer srv.Close()
	tgt := Target{Addr: addrOf(t, srv.URL), Spec: state.HealthSpec{Type: state.HealthHTTP, Path: "/up", Timeout: "1s"}}
	if err := Probe(t.Context(), tgt); err != nil {
		t.Fatal(err)
	}
	status.Store(302) // 3xx counts as healthy and is never followed
	if err := Probe(t.Context(), tgt); err != nil {
		t.Fatal(err)
	}
	status.Store(500)
	if err := Probe(t.Context(), tgt); err == nil || !strings.Contains(err.Error(), "HTTP 500") {
		t.Fatalf("err = %v", err)
	}
	tgt.Spec.ExpectStatus = "5xx"
	if err := Probe(t.Context(), tgt); err != nil {
		t.Fatal(err)
	}
}

func TestProbeTCP(t *testing.T) {
	ln, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ap := netip.MustParseAddrPort(ln.Addr().String())
	tgt := Target{Addr: ap, Spec: state.HealthSpec{Type: state.HealthTCP, Timeout: "1s"}}
	if err := Probe(t.Context(), tgt); err != nil {
		t.Fatal(err)
	}
	_ = ln.Close()
	if err := Probe(t.Context(), tgt); err == nil || err.Error() != "connection refused" {
		t.Fatalf("err = %v", err)
	}
}

func TestMonitorSync(t *testing.T) {
	var calls atomic.Int32
	changed := make(chan struct{}, 16)
	m := NewMonitor(t.Context(), MonitorOptions{
		Probe:    func(context.Context, Target) error { calls.Add(1); return nil },
		OnChange: func() { changed <- struct{}{} },
	})
	defer m.Close()
	tgt := Target{Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7", ContainerID: "c1", Addr: netip.MustParseAddrPort("10.0.0.2:80"), Spec: spec("1s", 1)}
	m.Sync([]Target{tgt})
	select {
	case <-changed:
	case <-time.After(5 * time.Second):
		t.Fatal("no state change")
	}
	if st, ok := m.Status(tgt.Workload); !ok || st.State != state.ProbePassing {
		t.Fatalf("%+v", st)
	}
	// Same target: state kept. New container: restarts from starting.
	m.Sync([]Target{tgt})
	if st, _ := m.Status(tgt.Workload); st.State != state.ProbePassing {
		t.Fatal("state lost on identical sync")
	}
	tgt.ContainerID = "c2"
	m.Sync([]Target{tgt})
	if st, _ := m.Status(tgt.Workload); st.State != state.ProbeStarting {
		t.Fatalf("not reset: %+v", st)
	}
	m.Sync(nil)
	if _, ok := m.Status(tgt.Workload); ok {
		t.Fatal("status kept after removal")
	}
}

const procTCP = `  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode
   0: 00000000:1F90 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1 1 0000000000000000 100 0 0 10 0
   1: 0100007F:1435 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 2 1 0000000000000000 100 0 0 10 0
   2: 0200000A:1F90 0300000A:C350 01 00000000:00000000 00:00000000 00000000     0        0 3 1 0000000000000000 100 0 0 10 0
`

const procTCP6 = `  sl  local_address                         remote_address                        st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode
   0: 00000000000000000000000000000000:0BB8 00000000000000000000000000000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 4 1 0000000000000000 100 0 0 10 0
   1: 00000000000000000000000001000000:1435 00000000000000000000000000000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 5 1 0000000000000000 100 0 0 10 0
`

func TestProcScanner(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "42", "net")
	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(filepath.Join(dir, "tcp"), []byte(procTCP), 0o644)
	_ = os.WriteFile(filepath.Join(dir, "tcp6"), []byte(procTCP6), 0o644)
	ls, err := ProcScanner{Root: root}.Listeners(42)
	if err != nil {
		t.Fatal(err)
	}
	want := []Listener{{Port: 3000}, {Port: 5173, LoopbackOnly: true}, {Port: 8080}}
	if len(ls) != len(want) {
		t.Fatalf("%+v", ls)
	}
	for i := range want {
		if ls[i] != want[i] {
			t.Fatalf("%+v", ls)
		}
	}
	if _, err := (ProcScanner{Root: root}).Listeners(43); !errors.Is(err, ErrUnknown) {
		t.Fatalf("err = %v", err)
	}
	if got := Hint(3000, ls); got != "" {
		t.Errorf("hint %q", got)
	}
	if got := Hint(5173, ls); !strings.Contains(got, "127.0.0.1:5173 only") {
		t.Errorf("hint %q", got)
	}
	if got := Hint(4000, ls); got != "the app listens on 3000, 5173, 8080, not 4000" {
		t.Errorf("hint %q", got)
	}
	if got := Hint(4000, []Listener{}); !strings.Contains(got, "does not listen") {
		t.Errorf("hint %q", got)
	}
}
