package guard

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"time"
)

// RootlessOptions describes a rootless Docker host (docs/05 §4, D49).
//
// With rootless Docker, "host network" is RootlessKit's network namespace,
// not the server's: Caddy (host network) binds 80/443 there, and nothing
// reaches it from outside. The guard exposes exactly the edge ports from
// the real host into that namespace through RootlessKit's port API, the
// same mechanism `docker run -p` uses on rootless hosts. Binding ports
// below 1024 on the host needs net.ipv4.ip_unprivileged_port_start ≤ 80
// (server.sh --rootless checks it).
type RootlessOptions struct {
	// APISocket is RootlessKit's API socket
	// ($XDG_RUNTIME_DIR/dockerd-rootless/api.sock); "" = edge ports unavailable.
	APISocket string
	// ParentIP is the host address to bind (default 0.0.0.0).
	ParentIP string
}

// edgePorts are the only ports the guard exposes: HTTP, HTTPS and HTTP/3.
var edgePorts = []struct {
	proto string
	port  int
}{{"tcp", 80}, {"tcp", 443}, {"udp", 443}}

type rkPortSpec struct {
	Proto      string `json:"proto"`
	ParentIP   string `json:"parentIP"`
	ParentPort int    `json:"parentPort"`
	ChildPort  int    `json:"childPort"`
}

type rkPortStatus struct {
	ID   int        `json:"id"`
	Spec rkPortSpec `json:"spec"`
}

func (s *Server) serveEdgePorts(w http.ResponseWriter, r *http.Request) {
	_, _ = io.Copy(io.Discard, io.LimitReader(r.Body, 4<<10))
	ro := s.o.Rootless
	if ro == nil || ro.APISocket == "" {
		writeJSON(w, http.StatusNotFound, map[string]string{"message": RefusalPrefix + "this server does not run rootless Docker with a RootlessKit API"})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 20*time.Second)
	defer cancel()
	ports, err := s.EnsureEdgePorts(ctx)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"message": RefusalPrefix + err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ports": ports})
}

// EnsureEdgePorts exposes the edge ports that are not exposed yet
// (idempotent: RootlessKit forgets them when the rootless daemon restarts,
// launchroomd asks again on every reconcile).
func (s *Server) EnsureEdgePorts(ctx context.Context) ([]EdgePort, error) {
	ro := s.o.Rootless
	hc := &http.Client{Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, "unix", ro.APISocket)
	}}}
	defer hc.CloseIdleConnections()
	parent := ro.ParentIP
	if parent == "" {
		parent = "0.0.0.0"
	}
	var have []rkPortStatus
	if err := rkCall(ctx, hc, http.MethodGet, nil, &have); err != nil {
		return nil, fmt.Errorf("RootlessKit API: %w", err)
	}
	out := make([]EdgePort, 0, len(edgePorts))
	for _, p := range edgePorts {
		e := EdgePort{Proto: p.proto, Port: p.port}
		for _, h := range have {
			if h.Spec.Proto == p.proto && h.Spec.ParentPort == p.port && h.Spec.ChildPort == p.port {
				e.Exposed = true
			}
		}
		if !e.Exposed {
			var st rkPortStatus
			err := rkCall(ctx, hc, http.MethodPost, rkPortSpec{Proto: p.proto, ParentIP: parent, ParentPort: p.port, ChildPort: p.port}, &st)
			if err != nil {
				e.Message = truncate(err.Error(), 300)
			} else {
				e.Exposed = true
			}
		}
		out = append(out, e)
	}
	return out, nil
}

func rkCall(ctx context.Context, hc *http.Client, method string, in, out any) error {
	var body io.Reader
	if in != nil {
		b, err := json.Marshal(in)
		if err != nil {
			return err
		}
		body = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, "http://rootlesskit/v1/ports", body)
	if err != nil {
		return err
	}
	if in != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	res, err := hc.Do(req)
	if err != nil {
		return err
	}
	defer func() { _ = res.Body.Close() }()
	raw, err := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if err != nil {
		return err
	}
	if res.StatusCode/100 != 2 {
		var e struct {
			Message string `json:"message"`
		}
		_ = json.Unmarshal(raw, &e)
		if e.Message == "" {
			e.Message = truncate(string(raw), 200)
		}
		return fmt.Errorf("HTTP %d: %s", res.StatusCode, e.Message)
	}
	return json.Unmarshal(raw, out)
}
