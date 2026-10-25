package guard

import (
	"archive/tar"
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"path"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/moby/moby/api/types/container"
)

// kind of an existing container, for what the guard lets launchroomd do with it.
type kind int

const (
	kindForeign kind = iota
	// kindServer: an object of a launchroom server (managed + server label).
	kindServer
	// kindUpdater: a self-update supervisor (start, stop, wait, logs, remove only).
	kindUpdater
	// kindCompose: launchroomd's or the guard's own compose container (inspect only).
	kindCompose
)

func (c *ctr) kind() kind {
	l := c.Labels
	switch {
	case l[labelManaged] != "true":
		return kindForeign
	case isManaged(l):
		return kindServer
	case l[labelRole] == roleUpdater && reTaskLabel.MatchString(l["launchroom.update-task"]):
		return kindUpdater
	case slices.Contains([]string{"launchroomd", "dockerd-guard"}, l["com.docker.compose.service"]):
		return kindCompose
	}
	return kindForeign
}

// ---- exec ----

// execTable remembers the execs created through the guard: start, resize
// and inspect work only on those.
type execTable struct {
	mu sync.Mutex
	m  map[string]execEntry
}

type execEntry struct {
	container string
	tty       bool
	created   time.Time
}

const (
	maxExecs = 4096
	execTTL  = 48 * time.Hour
)

func newExecTable() *execTable { return &execTable{m: map[string]execEntry{}} }

func (t *execTable) add(id string, e execEntry) {
	t.mu.Lock()
	defer t.mu.Unlock()
	now := time.Now()
	for k, v := range t.m {
		if now.Sub(v.created) > execTTL {
			delete(t.m, k)
		}
	}
	if len(t.m) >= maxExecs {
		var oldest string
		for k, v := range t.m {
			if oldest == "" || v.created.Before(t.m[oldest].created) {
				oldest = k
			}
		}
		delete(t.m, oldest)
	}
	t.m[id] = e
}

func (t *execTable) get(id string) (execEntry, bool) {
	t.mu.Lock()
	defer t.mu.Unlock()
	e, ok := t.m[id]
	return e, ok
}

// sessionsAllowTTY reports whether LR_INTERACTIVE_SESSIONS allows consoles.
func (s *Server) sessionsAllowTTY() bool {
	switch s.o.InteractiveSessions {
	case "off", "data":
		return false
	}
	return true
}

func (s *Server) createExec(w http.ResponseWriter, r *http.Request, q *request) error {
	c, err := s.managedContainer(r.Context(), q)
	if err != nil {
		return err
	}
	if c.kind() != kindServer {
		return deny("no exec into %s", c.Name)
	}
	var req container.ExecCreateRequest
	if err := decodeStrict(r, &req); err != nil {
		return err
	}
	if err := s.checkExec(c, &req); err != nil {
		return err
	}
	body, err := encode(req)
	if err != nil {
		return err
	}
	// The answer carries the exec id: remember it.
	u := &url.URL{Scheme: "http", Host: "docker", Path: q.apiPath("/containers/" + c.ID + "/exec")}
	out, err := http.NewRequestWithContext(r.Context(), http.MethodPost, u.String(), body)
	if err != nil {
		return err
	}
	out.Header.Set("Content-Type", "application/json")
	res, err := s.upstream.Do(out)
	if err != nil {
		return fmt.Errorf("docker: %w", err)
	}
	defer func() { _ = res.Body.Close() }()
	raw, err := io.ReadAll(io.LimitReader(res.Body, 64<<10))
	if err != nil {
		return err
	}
	if res.StatusCode == http.StatusCreated || res.StatusCode == http.StatusOK {
		var v struct {
			ID string `json:"Id"`
		}
		if err := json.Unmarshal(raw, &v); err != nil || !reExecID.MatchString(v.ID) {
			return errors.New("docker: exec create: unexpected answer")
		}
		s.execs.add(v.ID, execEntry{container: c.ID, tty: req.Tty, created: time.Now()})
	}
	for _, h := range responseHeaders {
		if v := res.Header.Get(h); v != "" && h != "Content-Length" {
			w.Header().Set(h, v)
		}
	}
	w.WriteHeader(res.StatusCode)
	_, _ = w.Write(raw)
	return nil
}

// checkExec applies the exec rules: never privileged, argv only (no
// working dir or detach keys), consoles (TTY) only into workloads, as the
// container's own user and only when LR_INTERACTIVE_SESSIONS allows them
// (D35); system containers allow only the argv launchroomd uses.
func (s *Server) checkExec(c *ctr, req *container.ExecCreateRequest) error {
	if f := nonZeroOutside(reflect0(req), []string{"User", "Tty", "ConsoleSize", "AttachStdin", "AttachStdout", "AttachStderr", "Env", "Cmd"}); len(f) > 0 {
		return deny("exec fields %s are not allowed", strings.Join(f, ", "))
	}
	if len(req.Cmd) == 0 || len(req.Cmd) > 256 {
		return deny("exec needs an argv (1–256 items)")
	}
	for _, a := range req.Cmd {
		if len(a) > 64<<10 || strings.ContainsRune(a, 0) {
			return deny("exec argument too long or with NUL")
		}
	}
	for _, e := range req.Env {
		k, _, ok := strings.Cut(e, "=")
		if !ok || !reEnvKey.MatchString(k) || strings.ContainsRune(e, 0) || len(e) > 64<<10 {
			return deny("exec environment entry is not NAME=value")
		}
	}
	if req.User != "" && !reUser.MatchString(req.User) {
		return deny("exec user %q is not allowed", truncate(req.User, 32))
	}
	cls := c.class()
	if cls == classAccessHelper {
		// SFTP only: the file tools, without TTY, user override or environment.
		if req.Tty || req.User != "" || len(req.Env) > 0 || !slices.Contains(accessHelperTools, req.Cmd[0]) {
			return deny("access helpers run only the SFTP file tools")
		}
		return nil
	}
	if cls != classWorkload {
		sh, ok := shapes[cls]
		if !ok || sh.exec == nil {
			return deny("no exec into system container %s", c.Name)
		}
		if req.Tty || req.User != "" || len(req.Env) > 0 {
			return deny("system container execs run without TTY, user or environment")
		}
		if sh.execExact && !slices.Equal(req.Cmd, sh.exec) || !sh.execExact && !slices.Equal(req.Cmd[:min(len(req.Cmd), len(sh.exec))], sh.exec) {
			return deny("exec %q is not allowed in %s", truncate(req.Cmd[0], 64), c.Name)
		}
		return nil
	}
	if req.Tty {
		if !s.sessionsAllowTTY() {
			return deny("console sessions are off on this server (LR_INTERACTIVE_SESSIONS)")
		}
		if req.User != "" {
			return deny("console sessions run as the container's own user")
		}
	}
	return nil
}

func (s *Server) startExec(w http.ResponseWriter, r *http.Request, q *request) error {
	e, ok := s.execs.get(q.id)
	if !ok {
		return notFound("exec instance", q.id)
	}
	var req container.ExecStartRequest
	if err := decodeStrict(r, &req); err != nil {
		return err
	}
	if req.Detach {
		return deny("detached execs are not allowed")
	}
	if req.Tty != e.tty {
		return deny("exec start must match the exec's TTY setting")
	}
	body, err := json.Marshal(req)
	if err != nil {
		return err
	}
	if !strings.EqualFold(r.Header.Get("Upgrade"), "tcp") {
		return s.forward(w, r, q, q.apiPath("/exec/"+q.id+"/start"), nil, bytes.NewReader(body), "application/json")
	}
	return s.hijack(w, r, q.apiPath("/exec/"+q.id+"/start"), body)
}

// hijack forwards an upgraded (raw stream) request: the response head is
// relayed, then both directions are copied with half-close, so a closed
// stdin still lets the remaining output through (restores, imports).
func (s *Server) hijack(w http.ResponseWriter, r *http.Request, p string, body []byte) error {
	hj, ok := w.(http.Hijacker)
	if !ok {
		return errors.New("guard: connection cannot be hijacked")
	}
	up, err := s.dialUpstream(r.Context())
	if err != nil {
		return fmt.Errorf("docker: %w", err)
	}
	out, err := http.NewRequestWithContext(r.Context(), http.MethodPost, (&url.URL{Scheme: "http", Host: "docker", Path: p}).String(), bytes.NewReader(body))
	if err != nil {
		_ = up.Close()
		return err
	}
	out.Header.Set("Content-Type", "application/json")
	out.Header.Set("Connection", "Upgrade")
	out.Header.Set("Upgrade", "tcp")
	if err := out.Write(up); err != nil {
		_ = up.Close()
		return fmt.Errorf("docker: %w", err)
	}
	br := bufio.NewReader(up)
	res, err := http.ReadResponse(br, out)
	if err != nil {
		_ = up.Close()
		return fmt.Errorf("docker: %w", err)
	}
	if res.StatusCode != http.StatusSwitchingProtocols {
		defer func() { _ = up.Close() }()
		defer func() { _ = res.Body.Close() }()
		copyResponse(w, res)
		return nil
	}
	cc, cbuf, err := hj.Hijack()
	if err != nil {
		_ = up.Close()
		return err
	}
	// Relay the 101 head as Docker sent it (Content-Type tells raw from multiplexed).
	head := "HTTP/1.1 101 UPGRADED\r\nConnection: Upgrade\r\nUpgrade: tcp\r\n"
	if ct := res.Header.Get("Content-Type"); ct != "" && !strings.ContainsAny(ct, "\r\n") {
		head += "Content-Type: " + ct + "\r\n"
	}
	head += "\r\n"
	if _, err := cc.Write([]byte(head)); err != nil {
		_ = cc.Close()
		_ = up.Close()
		return nil
	}
	splice(cc, cbuf.Reader, up, br)
	return nil
}

type closeWriter interface{ CloseWrite() error }

// splice copies client→upstream and upstream→client; when one direction
// ends, only its write side is closed. Both connections close when both
// directions are done.
func splice(client net.Conn, clientBuf *bufio.Reader, up net.Conn, upBuf *bufio.Reader) {
	var wg sync.WaitGroup
	wg.Add(2)
	go func() {
		defer wg.Done()
		_, _ = io.Copy(up, clientBuf)
		if cw, ok := up.(closeWriter); ok {
			_ = cw.CloseWrite()
		} else {
			_ = up.Close()
		}
	}()
	go func() {
		defer wg.Done()
		_, _ = io.Copy(client, upBuf)
		if cw, ok := client.(closeWriter); ok {
			_ = cw.CloseWrite()
		} else {
			_ = client.Close()
		}
	}()
	wg.Wait()
	_ = client.Close()
	_ = up.Close()
}

// ---- archives ----

// maxSystemArchive bounds uploads into system containers (configs, certificates).
const maxSystemArchive = 64 << 20

func (s *Server) archive(w http.ResponseWriter, r *http.Request, q *request) error {
	c, err := s.managedContainer(r.Context(), q)
	if err != nil {
		return err
	}
	if c.kind() != kindServer {
		return deny("no archive access to %s", c.Name)
	}
	p := "/containers/" + c.ID + "/archive"
	if q.op != opArchivePut {
		return s.forward(w, r, q, q.apiPath(p), q.query, nil, "")
	}
	cls := c.class()
	if cls == classWorkload {
		// Unprivileged workloads: the stream goes through unchanged.
		return s.forward(w, r, q, q.apiPath(p), q.query, r.Body, "application/x-tar")
	}
	if cls == classVolumeHelper {
		return s.helperUpload(w, r, q, p)
	}
	sh, ok := shapes[cls]
	if !ok || len(sh.putRoots) == 0 {
		return deny("no uploads into %s", c.Name)
	}
	dst := q.query.Get("path")
	checked, err := checkTar(r.Body, dst, sh.putRoots)
	if err != nil {
		return err
	}
	return s.forward(w, r, q, q.apiPath(p), q.query, bytes.NewReader(checked), "application/x-tar")
}

// checkTar re-writes an upload for a system container: only directories and
// regular files, every entry inside one of roots.
func checkTar(r io.Reader, dst string, roots []string) ([]byte, error) {
	if path.Clean(dst) != dst || !strings.HasPrefix(dst, "/") {
		return nil, deny("archive destination %q is not clean", truncate(dst, 64))
	}
	tr := tar.NewReader(io.LimitReader(r, maxSystemArchive+1))
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	total := 0
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, deny("archive: %v", err)
		}
		if h.Typeflag != tar.TypeReg && h.Typeflag != tar.TypeDir {
			return nil, deny("archive entry %q: only files and directories", truncate(h.Name, 64))
		}
		full := path.Join(dst, h.Name) //nolint:gosec // G305: the joined path is only compared with the allowed roots, never opened
		inside := false
		for _, root := range roots {
			if full == root || strings.HasPrefix(full, root+"/") || (h.Typeflag == tar.TypeDir && strings.HasPrefix(root, full+"/")) {
				inside = true
			}
		}
		if !inside || strings.Contains(h.Name, "..") {
			return nil, deny("archive entry %q is outside %v", truncate(h.Name, 64), roots)
		}
		total += int(h.Size)
		if total > maxSystemArchive {
			return nil, deny("archive larger than %d bytes", maxSystemArchive)
		}
		hdr := &tar.Header{Typeflag: h.Typeflag, Name: h.Name, Mode: h.Mode & 0o777, Uid: h.Uid, Gid: h.Gid, Size: h.Size, ModTime: h.ModTime, Format: tar.FormatPAX}
		if err := tw.WriteHeader(hdr); err != nil {
			return nil, err
		}
		if h.Typeflag == tar.TypeReg {
			if _, err := io.CopyN(tw, tr, h.Size); err != nil {
				return nil, deny("archive: %v", err)
			}
		}
	}
	if err := tw.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// helperUpload re-encodes an upload into a volume helper entry by entry,
// streaming (volume restores and imports can be large), and allows exactly
// the shape launchroomd writes (D55, docs/14 §12.4, docs/09 R35):
// destination "/", names "data" or below (clean, relative), directories,
// regular files, symlinks and hard links to an earlier file; setuid/setgid
// bits, PAX records (extended attributes) and owner names are dropped, FIFOs
// skipped, device nodes and every other type refused. A refused entry is
// never forwarded: Docker gets a truncated stream and fails the extraction.
// copyUIDGID may stay on: a helper has no user, so it keeps the archive's
// numeric owners either way.
func (s *Server) helperUpload(w http.ResponseWriter, r *http.Request, q *request, p string) error {
	if dst := q.query.Get("path"); dst != "/" {
		return deny("uploads into volume helpers extract at / only, not %q", truncate(dst, 64))
	}
	pr, pw := io.Pipe()
	done := make(chan error, 1)
	go func() {
		err := rewriteVolumeTar(pw, r.Body)
		_ = pw.CloseWithError(err)
		done <- err
	}()
	fwdErr := s.forward(w, r, q, q.apiPath(p), q.query, pr, "application/x-tar")
	_ = pr.CloseWithError(io.ErrClosedPipe)
	if err := <-done; err != nil {
		var ref *refusal
		if errors.As(err, &ref) {
			return err
		}
	}
	return fwdErr
}

// rewriteVolumeTar copies src to dst in the volume helper shape.
func rewriteVolumeTar(dst io.Writer, src io.Reader) error {
	tr := tar.NewReader(src)
	tw := tar.NewWriter(dst)
	files := map[string]bool{}
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return deny("archive: %v", err)
		}
		name, ok := helperEntryName(h.Name)
		if !ok {
			return deny("archive entry %q is outside the volume (data/)", truncate(h.Name, 64))
		}
		out := &tar.Header{Name: name, Mode: h.Mode & 0o1777, Uid: h.Uid, Gid: h.Gid, ModTime: h.ModTime, Format: tar.FormatPAX}
		switch h.Typeflag {
		case tar.TypeReg, tar.TypeRegA: //nolint:staticcheck // legacy regular files
			out.Typeflag, out.Size = tar.TypeReg, h.Size
			files[name] = true
		case tar.TypeDir:
			out.Typeflag, out.Name = tar.TypeDir, name+"/"
		case tar.TypeSymlink:
			if h.Linkname == "" || strings.ContainsRune(h.Linkname, 0) || len(h.Linkname) > 4096 {
				return deny("archive entry %q: invalid symlink", truncate(h.Name, 64))
			}
			out.Typeflag, out.Linkname = tar.TypeSymlink, h.Linkname
		case tar.TypeLink:
			target, ok := helperEntryName(h.Linkname)
			if !ok || !files[target] {
				return deny("archive entry %q: hard links only to an earlier file of the archive", truncate(h.Name, 64))
			}
			out.Typeflag, out.Linkname = tar.TypeLink, target
			files[name] = true
		case tar.TypeFifo, tar.TypeXGlobalHeader:
			continue
		default:
			return deny("archive entry %q: type %q is not allowed in a volume (device nodes and special files)", truncate(h.Name, 64), string(h.Typeflag))
		}
		if err := tw.WriteHeader(out); err != nil {
			return err
		}
		if out.Typeflag == tar.TypeReg {
			if _, err := io.CopyN(tw, tr, h.Size); err != nil {
				return err
			}
		}
	}
	return tw.Close()
}

// helperEntryName returns the clean name if it is "data" or below it.
func helperEntryName(name string) (string, bool) {
	if name == "" || strings.HasPrefix(name, "/") || strings.ContainsRune(name, 0) {
		return "", false
	}
	for _, part := range strings.Split(strings.TrimSuffix(name, "/"), "/") {
		if part == ".." {
			return "", false
		}
	}
	clean := path.Clean(strings.TrimPrefix(name, "./"))
	if clean != "data" && !strings.HasPrefix(clean, "data/") {
		return "", false
	}
	return clean, true
}
