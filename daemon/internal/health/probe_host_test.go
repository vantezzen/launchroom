package health

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// djangoLike answers like a Django app with ALLOWED_HOSTS: 400 with a
// DisallowedHost page for any other host, 404 on / (no root view) otherwise.
func djangoLike(allowed string, seen *http.Header) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if seen != nil {
			*seen = r.Header.Clone()
			seen.Set("Host", r.Host)
		}
		if r.Host != allowed {
			w.Header().Set("Content-Type", "text/html; charset=utf-8")
			w.WriteHeader(http.StatusBadRequest)
			page := "<!DOCTYPE html><html><head><title>DisallowedHost at /</title><style>body{color:red}</style></head>" +
				"<body><h1>DisallowedHost at /</h1><pre>Invalid HTTP_HOST header: '" + r.Host + "'. You may need to add '" + r.Host + "' to ALLOWED_HOSTS.</pre></body></html>"
			_, _ = w.Write([]byte(page)) //nolint:gosec // a test server echoing the probe's host
			return
		}
		http.NotFound(w, r)
	})
}

// G45/G46 (OD3): the probe asks for the app's primary domain over HTTPS at
// the edge; the implicit "/" accepts 2xx-4xx, but an app that refuses its
// own domain fails with the host_not_allowed hint and the page's text.
func TestProbeSendsHostAndClassifiesRefusals(t *testing.T) {
	var seen http.Header
	srv := httptest.NewServer(djangoLike("app.example.com", &seen))
	defer srv.Close()
	addr := addrOf(t, srv.URL)
	base := state.HealthSpec{Type: state.HealthHTTP, Path: "/", ExpectStatus: "2xx-4xx", Timeout: "2s"}

	// Host set and allowed: the 404 on "/" passes (implicit path, 2xx-4xx).
	ok := base
	ok.Host = "app.example.com"
	if err := Probe(t.Context(), Target{Addr: addr, Spec: ok}); err != nil {
		t.Fatalf("allowed host: %v", err)
	}
	if seen.Get("Host") != "app.example.com" || seen.Get("X-Forwarded-Proto") != "https" || seen.Get("X-Forwarded-Host") != "app.example.com" {
		t.Fatalf("headers %v", seen)
	}

	// Host set but refused: fails although 400 is in 2xx-4xx.
	refused := base
	refused.Host = "other.example.com"
	err := Probe(t.Context(), Target{Addr: addr, Spec: refused})
	var he *HTTPError
	if !errors.As(err, &he) || he.Status != 400 || he.Hint != state.ProbeHostNotAllowed {
		t.Fatalf("err = %#v", err)
	}
	if !strings.HasPrefix(he.Body, "DisallowedHost at / DisallowedHost at / Invalid HTTP_HOST header: 'other.example.com'") ||
		strings.Contains(he.Body, "<") || strings.Contains(he.Body, "color:red") || len(he.Body) > state.MaxProbeBody {
		t.Fatalf("body %q", he.Body)
	}
	if !strings.Contains(err.Error(), "doesn't accept this domain") {
		t.Fatalf("message %q", err)
	}

	// No host (older dashboards): the container address is asked for; the
	// refusal is in range and passes, but it is not the visitors' answer.
	if err := Probe(t.Context(), Target{Addr: addr, Spec: base}); err != nil {
		t.Fatalf("no host: %v", err)
	}
	// Explicit paths keep 2xx-3xx: the 400 fails with the hint and body.
	strict := base
	strict.ExpectStatus = ""
	err = Probe(t.Context(), Target{Addr: addr, Spec: strict})
	if !errors.As(err, &he) || he.Hint != state.ProbeHostNotAllowed || he.Expect != DefaultExpectStatus {
		t.Fatalf("err = %#v", err)
	}
}

func TestProbeBodyOfServerErrors(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
		_, _ = w.Write([]byte("Internal Server Error: SQLSTATE[HY000] [2002] Connection refused\x00\x1b[31m\n\n  at db.php:12" + strings.Repeat(" x", 400)))
	}))
	defer srv.Close()
	err := Probe(t.Context(), Target{Addr: addrOf(t, srv.URL), Spec: state.HealthSpec{Type: state.HealthHTTP, Path: "/health", Timeout: "2s"}})
	var he *HTTPError
	if !errors.As(err, &he) || he.Hint != "" || he.Status != 500 {
		t.Fatalf("err = %#v", err)
	}
	if !strings.HasPrefix(he.Body, "Internal Server Error: SQLSTATE[HY000] [2002] Connection refused [31m at db.php:12 x x") || len(he.Body) > state.MaxProbeBody {
		t.Fatalf("body %q", he.Body)
	}
	// 421 always means the host.
	if classify(http.StatusMisdirectedRequest, nil) != state.ProbeHostNotAllowed || classify(403, []byte("Blocked hosts: app.example.com")) != state.ProbeHostNotAllowed ||
		classify(403, []byte("Forbidden")) != "" || classify(404, []byte("DisallowedHost")) != "" {
		t.Fatal("classify")
	}
}

// The monitor keeps the failing answer's body and hint, and when the check
// became failing (liveness restarts count from there).
func TestNextKeepsFailureDetails(t *testing.T) {
	now := time.Date(2026, 11, 17, 3, 0, 0, 0, time.UTC)
	sp := state.HealthSpec{Type: state.HealthHTTP, StartPeriod: "0s", FailureThreshold: 2}
	st := Next(Status{}, nil, now, now, sp)
	if !st.EverPassed || !st.FailingSince.IsZero() {
		t.Fatalf("%+v", st)
	}
	he := &HTTPError{Status: 403, Expect: "2xx-3xx", Hint: state.ProbeHostNotAllowed, Body: "Blocked hosts"}
	st = Next(st, he, now.Add(time.Second), now, sp)
	if st.State != state.ProbePassing || st.Body != "Blocked hosts" || st.Hint != state.ProbeHostNotAllowed || !st.FailingSince.IsZero() {
		t.Fatalf("one failure: %+v", st)
	}
	st = Next(st, he, now.Add(2*time.Second), now, sp)
	if st.State != state.ProbeFailing || !st.FailingSince.Equal(now.Add(2*time.Second)) {
		t.Fatalf("failing: %+v", st)
	}
	st = Next(st, errors.New("timed out"), now.Add(3*time.Second), now, sp)
	if !st.FailingSince.Equal(now.Add(2*time.Second)) || st.Body != "" || st.Hint != "" {
		t.Fatalf("still failing: %+v", st)
	}
	st = Next(st, nil, now.Add(4*time.Second), now, sp)
	if st.State != state.ProbePassing || !st.FailingSince.IsZero() {
		t.Fatalf("passing again: %+v", st)
	}
}
