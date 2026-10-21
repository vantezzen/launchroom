package guard

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"strings"
	"testing"

	"github.com/moby/moby/api/types/container"
)

// FuzzParseRequest: the route parser never panics, and whatever it accepts
// is a clean, allow-listed path with allow-listed query keys.
func FuzzParseRequest(f *testing.F) {
	for _, s := range []string{
		"GET /v1.52/containers/json?all=1", "POST /v1.52/containers/create?name=lr-x", "GET /images/a/b@sha256:x/json",
		"DELETE /v1.52/images/127.0.0.1:5000/x", "POST /exec/" + strings.Repeat("a", 64) + "/start", "GET /v1.52/../info",
		"PUT /containers/x/archive?path=/data", "GET /containers/x/logs?tail=all&tail=1", "POST /v1.99/build",
	} {
		f.Add(s)
	}
	f.Fuzz(func(t *testing.T, s string) {
		m, rest, _ := strings.Cut(s, " ")
		u, err := url.ParseRequestURI(rest)
		if err != nil || len(m) == 0 || len(m) > 10 {
			return
		}
		r := &http.Request{Method: m, URL: u, Header: http.Header{}}
		q, err := parseRequest(r)
		if err != nil {
			return
		}
		p := u.EscapedPath()
		if strings.Contains(p, "..") && q.op != opImageInspect && q.op != opImageRemove {
			t.Fatalf("accepted %q", p)
		}
		if strings.Contains(p, "%") || strings.Contains(p, "//") {
			t.Fatalf("accepted unclean %q", p)
		}
		if q.op == opImageInspect || q.op == opImageRemove {
			if strings.Contains(q.id, "..") || strings.Contains(q.id, "//") {
				t.Fatalf("accepted image ref %q", q.id)
			}
		}
	})
}

// FuzzCheckCreate: arbitrary create bodies never panic the checks, and
// nothing privileged ever passes.
func FuzzCheckCreate(f *testing.F) {
	raw, _ := json.Marshal(workload())
	f.Add("lr-wl_01j9z8x7w6v5t4s3r2q1p0n9m8", raw)
	f.Add("lr-system-caddy", []byte(`{"Image":"x","HostConfig":{"NetworkMode":"host","Privileged":true}}`))
	f.Add("lr-x", []byte(`{"HostConfig":{"Binds":["/:/h"]}}`))
	h := newHarness(f)
	f.Fuzz(func(t *testing.T, name string, body []byte) {
		err := h.srv.CheckCreate(context.Background(), name, body)
		if err != nil {
			return
		}
		var req container.CreateRequest
		if json.Unmarshal(body, &req) != nil || req.HostConfig == nil {
			t.Fatal("accepted an undecodable body")
		}
		hc := req.HostConfig
		if hc.Privileged || len(hc.Binds) > 0 || hc.PidMode != "" || hc.IpcMode == "host" || hc.UsernsMode != "" || len(hc.DeviceRequests) > 0 {
			t.Fatalf("accepted a privileged container: %s", body)
		}
		for _, m := range hc.Mounts {
			if m.Type != "volume" {
				t.Fatalf("accepted a %s mount", m.Type)
			}
		}
	})
}
