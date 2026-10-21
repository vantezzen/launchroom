package backup

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type entry struct {
	name     string
	typ      byte
	body     string
	uid      int
	mode     int64
	linkname string
}

func tarStream(t *testing.T, entries ...entry) *bytes.Buffer {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	for _, e := range entries {
		typ := e.typ
		if typ == 0 {
			typ = tar.TypeReg
		}
		h := &tar.Header{Name: e.name, Typeflag: typ, Mode: e.mode, Uid: e.uid, Gid: e.uid, Linkname: e.linkname, ModTime: time.Unix(1, 0)}
		if typ == tar.TypeReg {
			h.Size = int64(len(e.body))
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if typ == tar.TypeReg {
			_, _ = tw.Write([]byte(e.body))
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	return &buf
}

func readTarHeaders(t *testing.T, r io.Reader) map[string]*tar.Header {
	t.Helper()
	out := map[string]*tar.Header{}
	tr := tar.NewReader(r)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		out[h.Name] = h
	}
}

func TestBaseLayoutPrefixesDataDirAddsWalAndSignal(t *testing.T) {
	var out bytes.Buffer
	l := &baseLayout{tw: tar.NewWriter(&out), rel: "18/docker", files: map[string]bool{}, dirs: map[string]bool{}}
	base := tarStream(t,
		entry{name: "backup_label", body: "START WAL LOCATION", uid: 70, mode: 0o600},
		entry{name: "./pg_wal/", typ: tar.TypeDir, uid: 70, mode: 0o700},
		entry{name: "pg_wal/000000010000000000000002", body: "seg2-from-base", uid: 70, mode: 0o600},
		entry{name: "launchroom-wal/000000010000000000000001", body: "leftover", uid: 70, mode: 0o600},
		entry{name: "standby.signal", uid: 70, mode: 0o600},
		entry{name: "global/pg_control", body: "ctl", uid: 70, mode: 0o4600},
	)
	if err := l.base(base); err != nil {
		t.Fatal(err)
	}
	wal := tarStream(t,
		entry{name: "wal/000000010000000000000002", body: "seg2-shipped", mode: 0o600},
		entry{name: "wal/000000010000000000000003", body: "seg3", mode: 0o600},
		entry{name: "wal/00000002.history", body: "1\t0/3000000\tno recovery target specified", mode: 0o600},
		entry{name: "wal/000000010000000000000003.partial", body: "partial", mode: 0o600},
		entry{name: "wal/archtemp.000000010000000000000004.1.2", body: "temp", mode: 0o600},
		entry{name: "elsewhere/000000010000000000000005", body: "outside", mode: 0o600},
	)
	if err := l.wal(wal); err != nil {
		t.Fatal(err)
	}
	if err := l.finish(WalDirName); err != nil {
		t.Fatal(err)
	}
	got := readTarHeaders(t, &out)
	for _, want := range []string{"18/", "18/docker/", "18/docker/backup_label", "18/docker/pg_wal/", "18/docker/global/pg_control",
		"18/docker/pg_wal/000000010000000000000003", "18/docker/pg_wal/00000002.history", "18/docker/standby.signal", "launchroom-wal/"} {
		if got[want] == nil {
			t.Errorf("missing %s (have %v)", want, keys(got))
		}
	}
	for _, unwanted := range []string{"18/docker/launchroom-wal/000000010000000000000001", "18/docker/pg_wal/000000010000000000000003.partial",
		"18/docker/pg_wal/archtemp.000000010000000000000004.1.2", "18/docker/pg_wal/000000010000000000000005"} {
		if got[unwanted] != nil {
			t.Errorf("unexpected %s", unwanted)
		}
	}
	if got["18/docker/"].Mode != 0o700 || got["18/docker/"].Uid != 70 || got["18/docker/pg_wal/000000010000000000000003"].Uid != 70 {
		t.Errorf("owners/modes: %+v %+v", got["18/docker/"], got["18/docker/pg_wal/000000010000000000000003"])
	}
	if got["18/docker/global/pg_control"].Mode&0o4000 != 0 {
		t.Error("setuid bit kept")
	}
	// The base backup's own copy of a segment wins over the shipped one.
	if got["18/docker/pg_wal/000000010000000000000002"].Size != int64(len("seg2-from-base")) {
		t.Error("shipped segment replaced the base backup's")
	}
}

func TestBaseLayoutRefusesUnsafeEntries(t *testing.T) {
	for name, e := range map[string]entry{
		"escape": {name: "../etc/passwd", body: "x"},
		"abs":    {name: "/etc/passwd", body: "x"},
		"device": {name: "dev", typ: tar.TypeChar},
	} {
		l := &baseLayout{tw: tar.NewWriter(io.Discard), files: map[string]bool{}, dirs: map[string]bool{}}
		if err := l.base(tarStream(t, entry{name: "PG_VERSION", body: "18"}, e)); !errors.Is(err, errUnsafeArchive) {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestReplayCommand(t *testing.T) {
	at := time.Date(2026, 10, 19, 12, 30, 5, 123456000, time.FixedZone("CEST", 7200))
	cmd := replayCommand(&at, "/var/lib/postgresql/launchroom-wal")
	want := []string{"postgres", "-c", "listen_addresses=", "-c", "hot_standby=on", "-c", "archive_mode=on", "-c", "archive_library=basic_archive",
		"-c", "basic_archive.archive_directory=/var/lib/postgresql/launchroom-wal",
		"-c", "recovery_target_time=2026-10-19 10:30:05.123456+00", "-c", "recovery_target_action=promote"}
	if !slices.Equal(cmd, want) {
		t.Fatalf("got %q", cmd)
	}
	if cmd := replayCommand(nil, ""); !slices.Contains(cmd, "archive_mode=off") || slices.ContainsFunc(cmd, func(s string) bool { return strings.HasPrefix(s, "recovery_target") }) {
		t.Fatalf("latest: %q", cmd)
	}
}

func TestPostgresTargetFromEnv(t *testing.T) {
	ok := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_PASSWORD": "pw", "POSTGRES_DB": "app"}
	if tgt, err := PostgresTargetFromEnv(ok); err != nil || tgt.User != "app" || tgt.Database != "app" {
		t.Fatalf("%+v %v", tgt, err)
	}
	bad := map[string]secret.Secret{"POSTGRES_USER": "app; DROP", "POSTGRES_PASSWORD": "pw", "POSTGRES_DB": "app"}
	if _, err := PostgresTargetFromEnv(bad); err == nil {
		t.Fatal("invalid role accepted")
	}
	extra := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_PASSWORD": "pw", "POSTGRES_DB": "app", "PGDATA": "/"}
	if _, err := PostgresTargetFromEnv(extra); err == nil {
		t.Fatal("extra name accepted")
	}
}

// walFakeDocker is a database container with an archive directory.
type walFakeDocker struct {
	files   map[string]string
	removed []string
	execs   [][]string
	users   []string
}

func (f *walFakeDocker) ListContainers(context.Context, map[string]string) ([]docker.Container, error) {
	return []docker.Container{{ID: "c1", Running: true, State: "running", Labels: map[string]string{}}}, nil
}

func (f *walFakeDocker) ExecStream(_ context.Context, _ string, o docker.ExecOptions) (docker.ExecResult, error) {
	f.execs = append(f.execs, o.Argv)
	f.users = append(f.users, o.User)
	switch o.Argv[0] {
	case "ls":
		var names []string
		for n := range f.files {
			names = append(names, n)
		}
		slices.Sort(names)
		_, _ = io.WriteString(o.Stdout, strings.Join(names, "\n")+"\n")
	case "rm":
		f.removed = append(f.removed, o.Argv[3:]...)
	}
	return docker.ExecResult{}, nil
}

func (f *walFakeDocker) StreamFromContainer(_ context.Context, _, src string) (io.ReadCloser, error) {
	entries := []entry{{name: "launchroom-wal/", typ: tar.TypeDir}}
	for n, body := range f.files {
		entries = append(entries, entry{name: "launchroom-wal/" + n, body: body, mode: 0o600, uid: 70})
	}
	return io.NopCloser(tarStreamNoT(entries)), nil
}

func tarStreamNoT(entries []entry) *bytes.Buffer {
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	for _, e := range entries {
		typ := e.typ
		if typ == 0 {
			typ = tar.TypeReg
		}
		h := &tar.Header{Name: e.name, Typeflag: typ, Mode: e.mode, Uid: e.uid}
		if typ == tar.TypeReg {
			h.Size = int64(len(e.body))
		}
		_ = tw.WriteHeader(h)
		if typ == tar.TypeReg {
			_, _ = tw.Write([]byte(e.body))
		}
	}
	_ = tw.Close()
	return &buf
}

func TestWalShipStoresAndRemovesOnlyCompletedFiles(t *testing.T) {
	fd := &walFakeDocker{files: map[string]string{
		"000000010000000000000001":                 "seg1",
		"000000010000000000000002":                 "seg2",
		"000000010000000000000002.00000028.backup": "label",
		"archtemp.000000010000000000000003.9.1":    "partial copy",
	}}
	h := newHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	a := NewWalArchiver(WalArchiverOptions{
		Engine: h.e, Docker: fd,
		Credentials: func(state.WorkloadID, *state.WalArchiveSpec) (Credentials, error) { return creds, nil },
	})
	spec := &state.WalArchiveSpec{Directory: "/var/lib/postgresql/launchroom-wal", Destination: dest, Tags: []state.BackupTag{"kind:wal"}, Interval: "30s"}
	res, err := a.Ship(t.Context(), "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK", spec)
	if err != nil {
		t.Fatal(err)
	}
	if res.Shipped != 3 || res.Pending != 0 || res.LastSegment != "000000010000000000000002" {
		t.Fatalf("result %+v", res)
	}
	slices.Sort(fd.removed)
	if !slices.Equal(fd.removed, []string{"/var/lib/postgresql/launchroom-wal/000000010000000000000001",
		"/var/lib/postgresql/launchroom-wal/000000010000000000000002", "/var/lib/postgresql/launchroom-wal/000000010000000000000002.00000028.backup"}) {
		t.Fatalf("removed %v", fd.removed)
	}
	for _, u := range fd.users {
		if u != "postgres" {
			t.Fatalf("exec as %q", u)
		}
	}
	var backupCall fakeCall
	for _, c := range h.calls() {
		if slices.Contains(c.Args, "backup") {
			backupCall = c
		}
	}
	stored := readTarHeaders(t, strings.NewReader(backupCall.Stdin))
	if len(stored) != 3 || stored["wal/000000010000000000000001"] == nil || stored["wal/archtemp.000000010000000000000003.9.1"] != nil {
		t.Fatalf("stored %v", keys(stored))
	}
	if !slices.Contains(backupCall.Args, WalFile) || !slices.Contains(backupCall.Args, "kind:wal") {
		t.Fatalf("restic args %v", backupCall.Args)
	}

	// A failed upload removes nothing.
	fd.removed = nil
	data, _ := json.Marshal(map[string]fakeStep{"backup": {Exit: 1, Stderr: "boom"}})
	if err := os.WriteFile(filepath.Join(h.dir, "script.json"), data, 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := a.Ship(t.Context(), "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK", spec); err == nil || len(fd.removed) != 0 {
		t.Fatalf("failed upload: %v, removed %v", err, fd.removed)
	}
}

func TestWalArchiverSyncReportsStatus(t *testing.T) {
	fd := &walFakeDocker{files: map[string]string{}}
	h := newHarness(t, map[string]fakeStep{})
	changed := make(chan struct{}, 8)
	a := NewWalArchiver(WalArchiverOptions{
		Engine: h.e, Docker: fd, OnChange: func() { changed <- struct{}{} },
		Credentials: func(state.WorkloadID, *state.WalArchiveSpec) (Credentials, error) { return creds, nil },
	})
	wl := state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	spec := &state.WalArchiveSpec{Directory: "/var/lib/postgresql/launchroom-wal", Destination: dest, Tags: []state.BackupTag{"kind:wal"}, Interval: "10s"}
	a.Sync(t.Context(), &state.NodeState{Workloads: []state.WorkloadSpec{{ID: wl, WalArchive: spec}}})
	select {
	case <-changed:
	case <-time.After(5 * time.Second):
		t.Fatal("no status")
	}
	st := a.Status()
	if len(st) != 1 || st[0].Workload != wl || st[0].State != state.WalArchiveActive || st[0].PendingSegments != 0 {
		t.Fatalf("status %+v", st)
	}
	a.Sync(t.Context(), &state.NodeState{})
	if len(a.Status()) != 0 {
		t.Fatal("status kept after removal")
	}
	a.Stop(t.Context())
}

func keys(m map[string]*tar.Header) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	slices.Sort(out)
	return out
}
