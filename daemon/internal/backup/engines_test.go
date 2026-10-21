package backup

import (
	"archive/tar"
	"bytes"
	"io"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestMongoCommandsKeepThePasswordOutOfArgv(t *testing.T) {
	env := map[string]secret.Secret{"MONGO_INITDB_ROOT_USERNAME": "app", "MONGO_INITDB_ROOT_PASSWORD": "s3cret-pw", "MONGO_INITDB_DATABASE": "app"}
	en, err := engineFor(state.EngineMongoDB)
	if err != nil {
		t.Fatal(err)
	}
	for _, mk := range []func(map[string]secret.Secret) (command, error){en.dump, en.restore} {
		cmd, err := mk(env)
		if err != nil {
			t.Fatal(err)
		}
		if strings.Contains(strings.Join(cmd.argv, " "), "s3cret-pw") || string(cmd.stdin) != "s3cret-pw\n" {
			t.Fatalf("argv %v stdin %q", cmd.argv, cmd.stdin)
		}
		if !slices.Contains(cmd.argv, "--archive") || !slices.Contains(cmd.argv, "--username=app") {
			t.Fatalf("argv %v", cmd.argv)
		}
		got, _ := io.ReadAll(cmd.input(strings.NewReader("ARCHIVE")))
		if string(got) != "s3cret-pw\nARCHIVE" {
			t.Fatalf("stdin %q", got)
		}
		if m := cmd.masked("auth failed for s3cret-pw"); strings.Contains(m, "s3cret-pw") {
			t.Fatalf("masked %q", m)
		}
	}
	restore, _ := en.restore(env)
	if !slices.Contains(restore.argv, "--nsTo=app.$coll$") {
		t.Fatalf("restore argv %v", restore.argv)
	}
	env["MONGO_INITDB_DATABASE"] = "app;drop"
	if _, err := en.dump(env); err == nil {
		t.Fatal("an invalid database name was accepted")
	}
	env["MONGO_INITDB_DATABASE"] = "app"
	env["MONGO_INITDB_ROOT_PASSWORD"] = "pw\nmore"
	if _, err := en.dump(env); err == nil {
		t.Fatal("a password with a line break was accepted")
	}
}

func TestSnapshotFilesOfNewEngines(t *testing.T) {
	for e, want := range map[state.DatabaseEngine]string{state.EngineMongoDB: "mongodb.archive", state.EngineClickHouse: "clickhouse.zip"} {
		if got, err := SnapshotFile(e); err != nil || got != want {
			t.Errorf("%s: %q %v", e, got, err)
		}
	}
}

func TestUntarOne(t *testing.T) {
	mk := func(entries ...string) io.Reader {
		var buf bytes.Buffer
		tw := tar.NewWriter(&buf)
		for _, n := range entries {
			_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: n, Size: 3, Mode: 0o644})
			_, _ = tw.Write([]byte("zip"))
		}
		_ = tw.Close()
		return &buf
	}
	var out bytes.Buffer
	if err := untarOne(mk("launchroom-0123456789ab.zip"), "launchroom-0123456789ab.zip", &out); err != nil || out.String() != "zip" {
		t.Fatalf("%v %q", err, out.String())
	}
	if err := untarOne(mk("launchroom-0123456789ab.zip", "other"), "launchroom-0123456789ab.zip", io.Discard); err == nil {
		t.Fatal("a second entry was accepted")
	}
	if err := untarOne(mk(), "x.zip", io.Discard); err == nil {
		t.Fatal("an empty archive was accepted")
	}
	if !reBackupName.MatchString("launchroom-"+randomSuffix()+".zip") || reBackupName.MatchString("../x.zip") {
		t.Fatal("backup name grammar")
	}
}
