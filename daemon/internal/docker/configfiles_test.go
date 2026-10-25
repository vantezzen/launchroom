package docker

import (
	"archive/tar"
	"bytes"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestConfigFilesTar(t *testing.T) {
	files := []state.ConfigFile{
		{Path: "/docker-entrypoint-initdb.d/init-scripts/99-roles.sql", Content: "ALTER USER x;\n"},
		{Path: "/etc/kong.yml", Content: ""},
	}
	data, err := ConfigFilesTar(files, nil)
	if err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(bytes.NewReader(data))
	var names []string
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		if h.Typeflag != tar.TypeReg || h.Mode != 0o444 || h.Uid != 0 || h.Gid != 0 {
			t.Errorf("%s: type %c mode %o owner %d:%d", h.Name, h.Typeflag, h.Mode, h.Uid, h.Gid)
		}
		body, _ := io.ReadAll(tr)
		names = append(names, h.Name+"="+string(body))
	}
	want := []string{"docker-entrypoint-initdb.d/init-scripts/99-roles.sql=ALTER USER x;\n", "etc/kong.yml="}
	if strings.Join(names, "|") != strings.Join(want, "|") {
		t.Fatalf("entries %q", names)
	}
	if _, err := ConfigFilesTar([]state.ConfigFile{{Path: "/sys/x", Content: "x"}}, nil); err == nil {
		t.Fatal("bad path accepted")
	}
}

// createDaemon answers container create, archive upload and remove, and
// records the order of calls.
type createDaemon struct {
	mu       sync.Mutex
	calls    []string
	query    string
	archive  []byte
	failCopy bool
}

func (d *createDaemon) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	d.mu.Lock()
	defer d.mu.Unlock()
	w.Header().Set("Content-Type", "application/json")
	switch {
	case strings.HasSuffix(r.URL.Path, "/_ping"):
		w.Header().Set("Api-Version", "1.51")
		_, _ = w.Write([]byte("OK"))
		return
	case r.Method == http.MethodPost && strings.HasSuffix(r.URL.Path, "/containers/create"):
		d.calls = append(d.calls, "create")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"Id":"c1","Warnings":[]}`))
	case r.Method == http.MethodPut && strings.HasSuffix(r.URL.Path, "/containers/c1/archive"):
		d.calls = append(d.calls, "archive")
		d.query = r.URL.RawQuery
		d.archive, _ = io.ReadAll(r.Body)
		if d.failCopy {
			w.WriteHeader(http.StatusInternalServerError)
			_, _ = w.Write([]byte(`{"message":"boom"}`))
			return
		}
		w.WriteHeader(http.StatusOK)
	case r.Method == http.MethodDelete && strings.HasSuffix(r.URL.Path, "/containers/c1"):
		d.calls = append(d.calls, "remove")
		w.WriteHeader(http.StatusNoContent)
	default:
		d.calls = append(d.calls, r.Method+" "+r.URL.Path)
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte(`{"message":"not found"}`))
	}
}

func TestEngineCreateWritesConfigFiles(t *testing.T) {
	cc := func(files []state.ConfigFile) *ContainerCreate {
		return &ContainerCreate{
			Name:       "lr-wl_x",
			Config:     &container.Config{Image: "img", Labels: map[string]string{LabelManaged: "true", LabelServer: "srv_X"}},
			HostConfig: &container.HostConfig{},
			Files:      files,
		}
	}
	files := []state.ConfigFile{{Path: "/etc/a.conf", Content: "a"}}
	for _, fail := range []bool{false, true} {
		d := &createDaemon{failCopy: fail}
		srv := httptest.NewServer(d)
		e, err := NewEngine(EngineOptions{ServerID: "srv_X", Host: "tcp://" + strings.TrimPrefix(srv.URL, "http://")})
		if err != nil {
			t.Fatal(err)
		}
		id, err := e.CreateContainer(t.Context(), cc(files))
		_ = e.Close()
		srv.Close()
		got := strings.Join(d.calls, ",")
		if !fail {
			if err != nil || id != "c1" || got != "create,archive" {
				t.Fatalf("id %q err %v calls %s", id, err, got)
			}
			if !strings.Contains(d.query, "path=%2F") || !strings.Contains(d.query, "noOverwriteDirNonDir=true") || strings.Contains(d.query, "copyUIDGID=true") {
				t.Errorf("query %q", d.query)
			}
			if !bytes.Contains(d.archive, []byte("etc/a.conf")) {
				t.Error("archive misses the file")
			}
			continue
		}
		if err == nil || id != "" || got != "create,archive,remove" {
			t.Fatalf("failure: id %q err %v calls %s", id, err, got)
		}
	}

	// Without files nothing is uploaded.
	d := &createDaemon{}
	srv := httptest.NewServer(d)
	defer srv.Close()
	e, err := NewEngine(EngineOptions{ServerID: "srv_X", Host: "tcp://" + strings.TrimPrefix(srv.URL, "http://")})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = e.Close() }()
	if _, err := e.CreateContainer(t.Context(), cc(nil)); err != nil || strings.Join(d.calls, ",") != "create" {
		t.Fatalf("err %v calls %v", err, d.calls)
	}
}
