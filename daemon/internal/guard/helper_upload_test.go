package guard

import (
	"archive/tar"
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"
	"testing"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const helperID = "7777777777777777777777777777777777777777777777777777777777777777"

func helperHarness(t *testing.T) *harness {
	t.Helper()
	h := newHarness(t)
	labels := managed()
	labels[labelHelper] = "volume"
	h.fake.addContainer(helperID, "lr-vol-0123456789ab", labels)
	return h
}

type tentry struct {
	name, link string
	typ        byte
	mode       int64
	body       string
	pax        map[string]string
}

func tarBytes(t *testing.T, entries ...tentry) []byte {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	for _, e := range entries {
		h := &tar.Header{Name: e.name, Linkname: e.link, Typeflag: e.typ, Mode: e.mode, Size: int64(len(e.body)), PAXRecords: e.pax, Uid: 1000, Gid: 1000, Uname: "node"}
		if h.Mode == 0 {
			h.Mode = 0o644
		}
		if e.typ != tar.TypeReg {
			h.Size = 0
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if e.typ == tar.TypeReg {
			_, _ = tw.Write([]byte(e.body))
		}
	}
	_ = tw.Close()
	return buf.Bytes()
}

func readTar(t *testing.T, b []byte) []*tar.Header {
	t.Helper()
	var out []*tar.Header
	tr := tar.NewReader(bytes.NewReader(b))
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		out = append(out, h)
	}
}

func TestHelperUploadsAreReencoded(t *testing.T) {
	h := helperHarness(t)
	body := tarBytes(t,
		tentry{name: "data/", typ: tar.TypeDir, mode: 0o755},
		tentry{name: "data/app/", typ: tar.TypeDir, mode: 0o2775},
		tentry{name: "data/app/run", typ: tar.TypeReg, mode: 0o4755, body: "#!", pax: map[string]string{"SCHILY.xattr.security.capability": "x"}},
		tentry{name: "data/app/link", typ: tar.TypeSymlink, link: "run"},
		tentry{name: "data/app/hard", typ: tar.TypeLink, link: "data/app/run"},
		tentry{name: "data/fifo", typ: tar.TypeFifo},
	)
	ok(t, h.do(http.MethodPut, "/v1.52/containers/"+helperID+"/archive?path=/&copyUIDGID=1", body))
	r, got := h.fake.last()
	if r == nil || r.Method != http.MethodPut {
		t.Fatal("not forwarded")
	}
	hs := readTar(t, got)
	if len(hs) != 5 {
		t.Fatalf("forwarded %d entries", len(hs))
	}
	for _, x := range hs {
		if x.Mode&0o6000 != 0 || len(x.PAXRecords) > 0 && x.PAXRecords["SCHILY.xattr.security.capability"] != "" || x.Uname != "" {
			t.Errorf("%s kept mode %o / xattrs / owner name", x.Name, x.Mode)
		}
		if x.Uid != 1000 {
			t.Errorf("%s lost its numeric owner", x.Name)
		}
	}
}

func TestHelperUploadRefusals(t *testing.T) {
	for name, tc := range map[string]struct {
		query string
		body  []tentry
		want  string
	}{
		"traversal":     {"path=/", []tentry{{name: "data/../etc/passwd", typ: tar.TypeReg, body: "x"}}, "outside the volume"},
		"absolute":      {"path=/", []tentry{{name: "/data/x", typ: tar.TypeReg, body: "x"}}, "outside the volume"},
		"outside data":  {"path=/", []tentry{{name: "etc/passwd", typ: tar.TypeReg, body: "x"}}, "outside the volume"},
		"device node":   {"path=/", []tentry{{name: "data/sda", typ: tar.TypeBlock}}, "device nodes"},
		"char device":   {"path=/", []tentry{{name: "data/null", typ: tar.TypeChar}}, "device nodes"},
		"bad hard link": {"path=/", []tentry{{name: "data/x", typ: tar.TypeLink, link: "etc/shadow"}}, "hard links"},
		"wrong path":    {"path=/data", []tentry{{name: "x", typ: tar.TypeReg, body: "x"}}, "extract at /"},
	} {
		t.Run(name, func(t *testing.T) {
			h := helperHarness(t)
			before := h.fake.count()
			w := h.do(http.MethodPut, "/v1.52/containers/"+helperID+"/archive?"+tc.query, tarBytes(t, tc.body...))
			refused(t, w, tc.want)
			// Nothing of the refused entry reached Docker.
			if h.fake.count() > before+1 {
				if _, got := h.fake.last(); bytes.Contains(got, []byte("passwd")) || bytes.Contains(got, []byte("sda")) {
					t.Fatal("refused entry forwarded")
				}
			}
		})
	}
}

func TestHelperEntryName(t *testing.T) {
	for in, want := range map[string]string{"data": "data", "data/": "data", "./data/x": "data/x", "data//y": "data/y"} {
		if got, ok := helperEntryName(in); !ok || got != want {
			t.Errorf("%q → %q %v", in, got, ok)
		}
	}
	for _, in := range []string{"", "/data", "data/../x", "data/a/../b", "datax/y", "x/data"} {
		if _, ok := helperEntryName(in); ok {
			t.Errorf("%q passed", in)
		}
	}
}

// TestImportThrowawayShape: an import's throwaway (the database image with
// entrypoint sleep on its environment network) is a workload the guard
// creates, and execs into it pass (D55).
func TestImportThrowawayShape(t *testing.T) {
	h := newHarness(t)
	cc, err := backup.ImportContainer(testServer, state.ImageRef("127.0.0.1:5000/mirror/postgres@"+testDigest), "lr-e_abc", state.Resources{CPUs: 1, MemoryMB: 1024, Pids: 512}, 3600, policy.DefaultConfig())
	if err != nil {
		t.Fatal(err)
	}
	raw, err := json.Marshal(container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig, NetworkingConfig: cc.Networking})
	if err != nil {
		t.Fatal(err)
	}
	if err := h.srv.CheckCreate(t.Context(), cc.Name, raw); err != nil {
		t.Fatalf("import throwaway refused: %v", err)
	}
	if !strings.HasPrefix(cc.Name, "lr-import-") || len(cc.HostConfig.CapAdd) != 0 || cc.HostConfig.NetworkMode != "lr-e_abc" {
		t.Fatalf("unexpected shape %s %+v", cc.Name, cc.HostConfig)
	}
	// On a foreign network it is refused.
	cc.HostConfig.NetworkMode = "foreign-net"
	raw, _ = json.Marshal(container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig})
	if err := h.srv.CheckCreate(t.Context(), cc.Name, raw); err == nil {
		t.Fatal("throwaway on a foreign network passed")
	}
	h.fake.addContainer(strings.Repeat("2", 64), "lr-import-0123456789ab", managed())
	ok(t, h.do(http.MethodPost, "/v1.52/containers/lr-import-0123456789ab/exec", map[string]any{
		"Cmd": []string{"pg_restore", "--no-owner"}, "Env": []string{"PGPASSWORD=x"}, "AttachStdin": true, "AttachStdout": true, "AttachStderr": true,
	}))
}

// FuzzRewriteVolumeTar: whatever the rewrite lets through is a tar of the
// helper shape (names under data/, no device nodes, no setuid/setgid bits).
func FuzzRewriteVolumeTar(f *testing.F) {
	f.Add(tarBytes(&testing.T{}, tentry{name: "data/x", typ: tar.TypeReg, body: "x"}))
	f.Add(tarBytes(&testing.T{}, tentry{name: "data/../x", typ: tar.TypeReg, body: "x"}))
	f.Add(tarBytes(&testing.T{}, tentry{name: "data/d", typ: tar.TypeChar}))
	f.Fuzz(func(t *testing.T, in []byte) {
		var out bytes.Buffer
		if err := rewriteVolumeTar(&out, bytes.NewReader(in)); err != nil {
			return
		}
		tr := tar.NewReader(&out)
		for {
			h, err := tr.Next()
			if err != nil {
				return
			}
			if _, ok := helperEntryName(h.Name); !ok || h.Mode&0o6000 != 0 || h.Typeflag == tar.TypeChar || h.Typeflag == tar.TypeBlock {
				t.Fatalf("unsafe entry passed: %q %o %c", h.Name, h.Mode, h.Typeflag)
			}
		}
	})
}
