package guard

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Labels the guard checks (the same keys as internal/docker; guard_test.go
// keeps them in sync).
const (
	labelManaged = "launchroom.managed"
	labelServer  = "launchroom.server"
	labelSystem  = "launchroom.system"
	labelHelper  = "launchroom.helper"
	labelRole    = "launchroom.role"
)

// maxJSONBody bounds request bodies the guard decodes.
const maxJSONBody = 1 << 20

func (s *Server) handle(w http.ResponseWriter, r *http.Request, q *request) error {
	ctx := r.Context()
	switch q.op {
	case opPing, opVersion, opInfo, opDiskUsage, opImageInspect:
		return s.forward(w, r, q, r.URL.Path, q.query, nil, "")
	case opContainerList:
		return s.listManaged(w, r, q, "/containers/json")
	case opVolumeList:
		return s.listManaged(w, r, q, "/volumes")
	case opNetworkList:
		if f := q.query.Get("filters"); f != "" {
			if _, err := parseFilters(f); err != nil {
				return err
			}
		}
		return s.forward(w, r, q, q.apiPath("/networks"), q.query, nil, "")
	case opContainerCreate:
		return s.createContainer(w, r, q)
	case opContainerInspect:
		c, raw, err := s.inspectContainer(ctx, q, q.id)
		if err != nil {
			return err
		}
		if c.kind() == kindForeign {
			return notManaged("container", q.id)
		}
		writeRaw(w, raw)
		return nil
	case opContainerStart:
		c, err := s.managedContainer(ctx, q)
		if err != nil {
			return err
		}
		if k := c.kind(); k != kindServer && k != kindUpdater {
			return deny("%s is not started by launchroomd", c.Name)
		}
		if c.class() == classVolumeHelper {
			return deny("volume helper containers are never started")
		}
		return s.forward(w, r, q, q.apiPath("/containers/"+c.ID+"/start"), nil, nil, "")
	case opContainerStop, opContainerPause, opContainerUnpause, opContainerWait, opContainerLogs, opContainerStats:
		c, err := s.managedContainer(ctx, q)
		if err != nil {
			return err
		}
		if k := c.kind(); k != kindServer && (k != kindUpdater || q.op == opContainerPause || q.op == opContainerUnpause || q.op == opContainerStats) {
			return deny("launchroomd may not do this with %s", c.Name)
		}
		suffix := map[op]string{opContainerStop: "/stop", opContainerPause: "/pause", opContainerUnpause: "/unpause",
			opContainerWait: "/wait", opContainerLogs: "/logs", opContainerStats: "/stats"}[q.op]
		return s.forward(w, r, q, q.apiPath("/containers/"+c.ID+suffix), q.query, nil, "")
	case opContainerRemove:
		c, err := s.managedContainer(ctx, q)
		if err != nil {
			return err
		}
		if k := c.kind(); k != kindServer && k != kindUpdater {
			return deny("launchroomd may not remove %s", c.Name)
		}
		return s.forward(w, r, q, q.apiPath("/containers/"+c.ID), q.query, nil, "")
	case opContainerChanges, opContainerTop:
		return s.inspectWorkload(w, r, q)
	case opArchiveHead, opArchiveGet, opArchivePut:
		return s.archive(w, r, q)
	case opExecCreate:
		return s.createExec(w, r, q)
	case opExecStart:
		return s.startExec(w, r, q)
	case opExecResize, opExecInspect:
		if _, ok := s.execs.get(q.id); !ok {
			return notFound("exec instance", q.id)
		}
		suffix := "/resize"
		if q.op == opExecInspect {
			suffix = "/json"
		}
		return s.forward(w, r, q, q.apiPath("/exec/"+q.id+suffix), q.query, nil, "")
	case opNetworkInspect:
		n, raw, err := s.inspectNetwork(ctx, q, q.id)
		if err != nil {
			return err
		}
		if !isManaged(n.Labels) {
			return notManaged("network", q.id)
		}
		writeRaw(w, raw)
		return nil
	case opNetworkCreate:
		return s.createNetwork(w, r, q)
	case opNetworkRemove:
		n, _, err := s.inspectNetwork(ctx, q, q.id)
		if err != nil {
			return err
		}
		if !isManaged(n.Labels) {
			return notManaged("network", q.id)
		}
		return s.forward(w, r, q, q.apiPath("/networks/"+n.ID), nil, nil, "")
	case opNetworkConnect, opNetworkDisconnect:
		return s.connectNetwork(w, r, q)
	case opVolumeInspect:
		v, raw, err := s.inspectVolume(ctx, q, q.id)
		if err != nil {
			return err
		}
		if !isManaged(v.Labels) {
			return notManaged("volume", q.id)
		}
		writeRaw(w, raw)
		return nil
	case opVolumeCreate:
		return s.createVolume(w, r, q)
	case opVolumeRemove:
		v, _, err := s.inspectVolume(ctx, q, q.id)
		if err != nil {
			return err
		}
		if !isManaged(v.Labels) {
			return notManaged("volume", q.id)
		}
		return s.forward(w, r, q, q.apiPath("/volumes/"+v.Name), q.query, nil, "")
	case opImageCreate:
		return s.imageCreate(w, r, q)
	case opImageRemove:
		return s.imageRemove(w, r, q)
	}
	return deny("operation not implemented")
}

// isManaged reports whether labels mark a launchroom object of some server.
func isManaged(labels map[string]string) bool {
	return labels[labelManaged] == "true" && state.ServerID(labels[labelServer]).Validate() == nil
}

// ---- upstream calls ----

// forward sends the request upstream with an allow-listed header set and
// streams the answer back.
func (s *Server) forward(w http.ResponseWriter, r *http.Request, q *request, path string, query url.Values, body io.Reader, contentType string) error {
	u := &url.URL{Scheme: "http", Host: "docker", Path: path}
	if len(query) > 0 {
		u.RawQuery = query.Encode()
	}
	if body == nil && (r.Method == http.MethodPost || r.Method == http.MethodPut) {
		body = http.NoBody
	}
	out, err := http.NewRequestWithContext(r.Context(), r.Method, u.String(), body)
	if err != nil {
		return err
	}
	if ua := r.Header.Get("User-Agent"); ua != "" {
		out.Header.Set("User-Agent", truncate(ua, 128))
	}
	if contentType != "" {
		out.Header.Set("Content-Type", contentType)
	}
	if q.op == opImageCreate {
		if a := r.Header.Get("X-Registry-Auth"); a != "" && len(a) < 16<<10 {
			out.Header.Set("X-Registry-Auth", a)
		}
	}
	res, err := s.upstream.Do(out) //nolint:gosec // G704: the transport only dials the local Docker socket
	if err != nil {
		return fmt.Errorf("docker: %w", err)
	}
	defer func() { _ = res.Body.Close() }()
	copyResponse(w, res)
	return nil
}

var responseHeaders = []string{
	"Content-Type", "Content-Length", "Api-Version", "Docker-Experimental", "Ostype", "Server",
	"Cache-Control", "Pragma", "Builder-Version", "Swarm", "X-Docker-Container-Path-Stat",
}

func copyResponse(w http.ResponseWriter, res *http.Response) {
	for _, h := range responseHeaders {
		if v := res.Header.Get(h); v != "" {
			w.Header().Set(h, v)
		}
	}
	w.WriteHeader(res.StatusCode)
	fl, _ := w.(http.Flusher)
	buf := make([]byte, 32<<10)
	for {
		n, err := res.Body.Read(buf)
		if n > 0 {
			if _, werr := w.Write(buf[:n]); werr != nil {
				return
			}
			if fl != nil {
				fl.Flush()
			}
		}
		if err != nil {
			return
		}
	}
}

func writeRaw(w http.ResponseWriter, raw []byte) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(raw)
}

// get performs one of the guard's own GET calls and returns the body (≤ 8 MiB).
func (s *Server) get(ctx context.Context, q *request, path string, query url.Values) (int, []byte, error) {
	ctx, cancel := context.WithTimeout(ctx, s.o.CallTimeout)
	defer cancel()
	u := &url.URL{Scheme: "http", Host: "docker", Path: q.apiPath(path)}
	if len(query) > 0 {
		u.RawQuery = query.Encode()
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return 0, nil, err
	}
	res, err := s.upstream.Do(req)
	if err != nil {
		return 0, nil, fmt.Errorf("docker: %w", err)
	}
	defer func() { _ = res.Body.Close() }()
	b, err := io.ReadAll(io.LimitReader(res.Body, 8<<20))
	return res.StatusCode, b, err
}

// container is what the guard needs to know about an existing container.
type ctr struct {
	ID     string
	Name   string
	Image  string // image id
	Labels map[string]string
	// Binds are host paths mounted into it (only updater containers have any).
	Binds   []string
	Running bool
}

func (c *ctr) class() class {
	return classify(strings.TrimPrefix(c.Name, "/"), c.Labels)
}

// inspectContainer inspects ref upstream. A missing container is a 404
// refusal (launchroomd sees Docker's "not found").
func (s *Server) inspectContainer(ctx context.Context, q *request, ref string) (*ctr, []byte, error) {
	query := url.Values{}
	if q != nil && q.op == opContainerInspect && q.query.Get("size") != "" {
		query.Set("size", q.query.Get("size"))
	}
	status, raw, err := s.get(ctx, q, "/containers/"+ref+"/json", query)
	if err != nil {
		return nil, nil, err
	}
	if status == http.StatusNotFound {
		return nil, nil, notFound("container", ref)
	}
	if status != http.StatusOK {
		return nil, nil, fmt.Errorf("docker: inspect container %s: HTTP %d", short(ref), status)
	}
	var v struct {
		ID     string `json:"Id"`
		Name   string
		Image  string
		Config *struct {
			Labels map[string]string
		}
		State *struct {
			Running bool
		}
		Mounts []struct {
			Type   string
			Source string
		}
	}
	if err := json.Unmarshal(raw, &v); err != nil {
		return nil, nil, fmt.Errorf("docker: inspect container: %w", err)
	}
	if !reExecID.MatchString(v.ID) {
		return nil, nil, errors.New("docker: inspect container: unexpected id")
	}
	c := &ctr{ID: v.ID, Name: strings.TrimPrefix(v.Name, "/"), Image: v.Image}
	if v.Config != nil {
		c.Labels = v.Config.Labels
	}
	if v.State != nil {
		c.Running = v.State.Running
	}
	for _, m := range v.Mounts {
		if m.Type == "bind" {
			c.Binds = append(c.Binds, m.Source)
		}
	}
	return c, raw, nil
}

// inspectWorkload forwards the read-only inspection routes (container
// changes and processes, docs/22 §7.3) for a running workload container of
// this server, addressed by its full id. System containers, the volume
// helper and the update supervisor are refused; the query is always empty
// (the route table refuses every key, top's ps_args included).
func (s *Server) inspectWorkload(w http.ResponseWriter, r *http.Request, q *request) error {
	if !reExecID.MatchString(q.id) {
		return deny("container inspection needs the container's full id, not %q", truncate(q.id, 64))
	}
	c, err := s.managedContainer(r.Context(), q)
	if err != nil {
		return err
	}
	if c.kind() != kindServer || c.class() != classWorkload || c.Labels[labelSystem] != "" || c.Labels[labelHelper] != "" || c.ID != q.id {
		return deny("container inspection is only allowed for workload containers, not %s", c.Name)
	}
	suffix := "/changes"
	if q.op == opContainerTop {
		suffix = "/top"
	}
	return s.forward(w, r, q, q.apiPath("/containers/"+c.ID+suffix), nil, nil, "")
}

func (s *Server) managedContainer(ctx context.Context, q *request) (*ctr, error) {
	c, _, err := s.inspectContainer(ctx, q, q.id)
	if err != nil {
		return nil, err
	}
	if c.kind() == kindForeign {
		return nil, notManaged("container", q.id)
	}
	return c, nil
}

type networkInfo struct {
	ID     string `json:"Id"`
	Name   string
	Driver string
	Labels map[string]string
}

func (s *Server) inspectNetwork(ctx context.Context, q *request, ref string) (*networkInfo, []byte, error) {
	status, raw, err := s.get(ctx, q, "/networks/"+ref, nil)
	if err != nil {
		return nil, nil, err
	}
	if status == http.StatusNotFound {
		return nil, nil, notFound("network", ref)
	}
	if status != http.StatusOK {
		return nil, nil, fmt.Errorf("docker: inspect network %s: HTTP %d", short(ref), status)
	}
	var n networkInfo
	if err := json.Unmarshal(raw, &n); err != nil {
		return nil, nil, fmt.Errorf("docker: inspect network: %w", err)
	}
	if !reExecID.MatchString(n.ID) {
		return nil, nil, errors.New("docker: inspect network: unexpected id")
	}
	return &n, raw, nil
}

type volumeInfo struct {
	Name   string
	Driver string
	Labels map[string]string
}

func (s *Server) inspectVolume(ctx context.Context, q *request, name string) (*volumeInfo, []byte, error) {
	status, raw, err := s.get(ctx, q, "/volumes/"+name, nil)
	if err != nil {
		return nil, nil, err
	}
	if status == http.StatusNotFound {
		return nil, nil, notFound("volume", name)
	}
	if status != http.StatusOK {
		return nil, nil, fmt.Errorf("docker: inspect volume %s: HTTP %d", short(name), status)
	}
	var v volumeInfo
	if err := json.Unmarshal(raw, &v); err != nil {
		return nil, nil, fmt.Errorf("docker: inspect volume: %w", err)
	}
	if v.Name != name {
		return nil, nil, errors.New("docker: inspect volume: unexpected name")
	}
	return &v, raw, nil
}

// ---- lists ----

// parseFilters decodes Docker's filters argument ({"label": {"k=v": true}}).
func parseFilters(raw string) (map[string]map[string]bool, error) {
	f := map[string]map[string]bool{}
	if raw == "" {
		return f, nil
	}
	dec := json.NewDecoder(strings.NewReader(raw))
	if err := dec.Decode(&f); err != nil {
		return nil, deny("filters: %v", err)
	}
	if dec.More() {
		return nil, deny("filters: trailing data")
	}
	for k, vs := range f {
		switch k {
		case "label", "name", "id", "status", "driver", "dangling", "type":
		default:
			return nil, deny("filter %q is not allowed", truncate(k, 32))
		}
		if len(vs) > 32 {
			return nil, deny("too many %s filters", k)
		}
	}
	return f, nil
}

// listManaged forwards a container or volume list with the managed label
// filter added: launchroomd never sees anyone else's objects.
func (s *Server) listManaged(w http.ResponseWriter, r *http.Request, q *request, path string) error {
	f, err := parseFilters(q.query.Get("filters"))
	if err != nil {
		return err
	}
	if f["label"] == nil {
		f["label"] = map[string]bool{}
	}
	f["label"][labelManaged+"=true"] = true
	b, err := json.Marshal(f)
	if err != nil {
		return err
	}
	query := url.Values{}
	for k, v := range q.query {
		query[k] = v
	}
	query.Set("filters", string(b))
	return s.forward(w, r, q, q.apiPath(path), query, nil, "")
}

// decodeStrict reads a JSON body into v: bounded size, unknown fields and
// trailing data refused, application/json required.
func decodeStrict(r *http.Request, v any) error {
	if ct := r.Header.Get("Content-Type"); ct != "" && !strings.HasPrefix(ct, "application/json") {
		return deny("content type %q is not allowed", truncate(ct, 64))
	}
	raw, err := io.ReadAll(io.LimitReader(r.Body, maxJSONBody+1))
	if err != nil {
		return err
	}
	if len(raw) > maxJSONBody {
		return deny("request body larger than %d bytes", maxJSONBody)
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(v); err != nil {
		return deny("request body: %v", err)
	}
	if dec.More() {
		return deny("request body: trailing data")
	}
	return nil
}

// encode re-encodes a checked body for the upstream request.
func encode(v any) (io.Reader, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return nil, err
	}
	return bytes.NewReader(b), nil
}
