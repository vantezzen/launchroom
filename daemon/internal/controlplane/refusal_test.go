package controlplane

import (
	"net/http"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// G13: a refused signed request is kept (status, clock skew from the Date
// header, the dashboard's error text) until a report carrying it was accepted.
func TestLastRefusal(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, string(serverID), identity.KeySet{kid: f.pub()})

	if c.LastRefusal() != nil || !c.LastAccepted().IsZero() {
		t.Fatal("fresh client has a refusal or an accepted answer")
	}
	f.set(func(*http.Request, []byte) reply { return reply{status: 304, sign: "none"} })
	if _, _, err := c.FetchState(t.Context(), serverID, 7); err != nil {
		t.Fatal(err)
	}
	if c.LastAccepted().IsZero() || c.LastRefusal() != nil {
		t.Fatalf("after 304: accepted=%v refusal=%+v", c.LastAccepted(), c.LastRefusal())
	}

	f.set(func(*http.Request, []byte) reply {
		return reply{status: 401, body: `{"error":"unauthorized"}`, sign: "none"}
	})
	_, _, err := c.FetchState(t.Context(), serverID, 7)
	if !isAPI(err, 401) {
		t.Fatalf("err = %v", err)
	}
	r := c.LastRefusal()
	if r == nil || r.Status != 401 || r.Message != "unauthorized" || r.At.IsZero() || r.ClockSkewMs == nil {
		t.Fatalf("refusal = %+v", r)
	}
	if *r.ClockSkewMs > 2000 || *r.ClockSkewMs < -2000 {
		t.Fatalf("skew = %d ms against a local server", *r.ClockSkewMs)
	}
	rep := &state.Report{
		TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: serverID, ObservedAt: time.Now().UTC(), Seq: 1,
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: "0.1.0", LastRefusal: r},
	}
	if err := rep.Validate(); err != nil {
		t.Fatalf("a report carrying the refusal is invalid: %v", err)
	}

	// A newer refusal survives the ack of the older one.
	f.set(func(*http.Request, []byte) reply {
		return reply{status: 426, body: "<html>\x00upgrade</html>", sign: "none"}
	})
	_, _, _ = c.FetchState(t.Context(), serverID, 7)
	newer := c.LastRefusal()
	if newer == r || newer.Status != 426 || newer.Message != "<html>upgrade</html>" {
		t.Fatalf("newer = %+v", newer)
	}
	c.AckRefusal(r)
	if c.LastRefusal() != newer {
		t.Fatal("ack of an older refusal dropped the newer one")
	}
	c.AckRefusal(newer)
	if c.LastRefusal() != nil {
		t.Fatal("ack did not clear the refusal")
	}

	// A 403 about the request (a registry scope) is not a refusal of the server.
	f.set(func(*http.Request, []byte) reply { return reply{status: 403, body: "scope", sign: "none"} })
	_, _ = c.RegistryToken(t.Context(), &state.RegistryTokenRequest{
		TypeMeta: state.NewTypeMeta(state.KindRegistryTokenRequest),
		Scopes:   []state.RegistryScope{{Repository: "apps/web", Actions: []state.RegistryAction{state.RegistryPull}}},
	})
	if c.LastRefusal() != nil {
		t.Fatalf("registry 403 recorded: %+v", c.LastRefusal())
	}
}
