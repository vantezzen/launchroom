package backup

import (
	"archive/tar"
	"bytes"
	"context"
	"errors"
	"io"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type tarEntry struct {
	Typeflag byte
	Name     string
	Linkname string
	Mode     int64
	UID, GID int
	Body     string
	Xattrs   map[string]string
}

func tarOf(t *testing.T, entries ...tarEntry) string {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	for _, e := range entries {
		h := &tar.Header{Typeflag: e.Typeflag, Name: e.Name, Linkname: e.Linkname, Mode: e.Mode, Uid: e.UID, Gid: e.GID, Size: int64(len(e.Body)), Uname: "someone"}
		for k, v := range e.Xattrs {
			if h.PAXRecords == nil {
				h.PAXRecords = map[string]string{}
			}
			h.PAXRecords["SCHILY.xattr."+k] = v
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if _, err := io.WriteString(tw, e.Body); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.String()
}

func readTar(t *testing.T, s string) []*tar.Header {
	t.Helper()
	tr := tar.NewReader(strings.NewReader(s))
	var out []*tar.Header
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

func tarNames(t *testing.T, s string) []string {
	t.Helper()
	var names []string
	for _, h := range readTar(t, s) {
		names = append(names, h.Name)
	}
	return names
}

func sanitize(t *testing.T, in string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	err := sanitizeVolumeTar(&out, strings.NewReader(in+"trailing garbage after the end marker"))
	return out.String(), err
}

// 2026-10 review L4: a crafted snapshot (own repository password) can't
// plant setuid files, file capabilities or device nodes into a volume.
func TestSanitizeVolumeTarStripsPrivilege(t *testing.T) {
	t.Parallel()
	out, err := sanitize(t, tarOf(t,
		tarEntry{Typeflag: tar.TypeDir, Name: "data/", Mode: 0o755},
		tarEntry{Typeflag: tar.TypeReg, Name: "data/suid", Mode: 0o4755, Body: "#!", Xattrs: map[string]string{"security.capability": "\x01\x00\x00\x02"}},
		tarEntry{Typeflag: tar.TypeDir, Name: "data/sgid/", Mode: 0o2775, UID: 999, GID: 999},
		tarEntry{Typeflag: tar.TypeDir, Name: "data/tmp/", Mode: 0o1777},
		tarEntry{Typeflag: tar.TypeSymlink, Name: "data/link", Linkname: "/etc/passwd", Mode: 0o777},
		tarEntry{Typeflag: tar.TypeLink, Name: "data/hard", Linkname: "data/suid"},
		tarEntry{Typeflag: tar.TypeFifo, Name: "data/fifo", Mode: 0o644},
	))
	if err != nil {
		t.Fatal(err)
	}
	hs := readTar(t, out)
	got := map[string]*tar.Header{}
	for _, h := range hs {
		got[h.Name] = h
	}
	if len(hs) != 6 || got["data/fifo"] != nil {
		t.Fatalf("entries %v", tarNames(t, out))
	}
	if got["data/suid"].Mode != 0o755 || len(got["data/suid"].PAXRecords) != 0 || got["data/suid"].Uname != "" {
		t.Fatalf("setuid file kept privilege: %+v", got["data/suid"])
	}
	if got["data/sgid/"].Mode != 0o775 || got["data/sgid/"].Uid != 999 || got["data/sgid/"].Gid != 999 {
		t.Fatalf("setgid dir: %+v (numeric owners stay)", got["data/sgid/"])
	}
	if got["data/tmp/"].Mode != 0o1777 || got["data/link"].Linkname != "/etc/passwd" || got["data/hard"].Linkname != "data/suid" {
		t.Fatalf("sticky dir / links changed: %+v %+v %+v", got["data/tmp/"], got["data/link"], got["data/hard"])
	}
}

func TestSanitizeVolumeTarRefusesUnsafeEntries(t *testing.T) {
	t.Parallel()
	cases := map[string][]tarEntry{
		"char device":       {{Typeflag: tar.TypeChar, Name: "data/null", Mode: 0o666}},
		"block device":      {{Typeflag: tar.TypeBlock, Name: "data/sda", Mode: 0o660}},
		"outside data":      {{Typeflag: tar.TypeReg, Name: "etc/passwd", Mode: 0o644, Body: "x"}},
		"traversal":         {{Typeflag: tar.TypeReg, Name: "data/../etc/passwd", Mode: 0o644, Body: "x"}},
		"absolute":          {{Typeflag: tar.TypeReg, Name: "/data/x", Mode: 0o644, Body: "x"}},
		"hard link outside": {{Typeflag: tar.TypeLink, Name: "data/h", Linkname: "etc/shadow"}},
		"hard link unknown": {{Typeflag: tar.TypeLink, Name: "data/h", Linkname: "data/missing"}},
	}
	for name, entries := range cases {
		if _, err := sanitize(t, tarOf(t, entries...)); !errors.Is(err, errUnsafeArchive) {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}

func TestVolumeRestoreRefusesDeviceNodes(t *testing.T) {
	t.Parallel()
	archive := tarOf(t, tarEntry{Typeflag: tar.TypeDir, Name: "data/", Mode: 0o755}, tarEntry{Typeflag: tar.TypeChar, Name: "data/mem", Mode: 0o666})
	h, v := volumeHarness(t, map[string]fakeStep{"snapshots": {Stdout: volumeSnapshots}, "dump": {Stdout: archive}})
	_, err := h.e.Restore(context.Background(), &state.RestoreSpec{
		Kind: state.BackupVolume, Snapshot: snapshotID, Mode: state.RestoreInPlace,
		Target: state.BackupTarget{Volume: volumeID}, Destination: dest,
	}, creds, nil)
	if !errors.Is(err, errUnsafeArchive) {
		t.Fatalf("err = %v", err)
	}
	if strings.Contains(v.Extracted, "data/mem") {
		t.Fatal("device node reached the volume")
	}
}
