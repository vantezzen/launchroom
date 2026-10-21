package state

import (
	"strings"
	"testing"
	"time"
)

func pitrDest() BackupDestination {
	return BackupDestination{Kind: DestinationS3, Endpoint: "https://s3.example.com", Bucket: "backups", Credentials: Sealed{Sealed: SealedBox(strings.Repeat("A", 80))}}
}

func TestWalArchiveSpecMustLieInAWritableMount(t *testing.T) {
	mounts := []Mount{{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Target: "/var/lib/postgresql"}}
	ok := &WalArchiveSpec{Directory: "/var/lib/postgresql/launchroom-wal", Destination: pitrDest(), Tags: []BackupTag{"kind:wal"}, Interval: "30s"}
	var e errs
	ok.validate(&e, "w", mounts)
	if err := e.err(); err != nil {
		t.Fatal(err)
	}
	for name, mutate := range map[string]func(*WalArchiveSpec, *[]Mount){
		"outside":   func(s *WalArchiveSpec, _ *[]Mount) { s.Directory = "/tmp/wal" },
		"read-only": func(_ *WalArchiveSpec, m *[]Mount) { (*m)[0].ReadOnly = true },
		"no tags":   func(s *WalArchiveSpec, _ *[]Mount) { s.Tags = nil },
		"too fast":  func(s *WalArchiveSpec, _ *[]Mount) { s.Interval = "1s" },
		"dotdot":    func(s *WalArchiveSpec, _ *[]Mount) { s.Directory = "/var/lib/postgresql/../etc" },
	} {
		spec, m := *ok, []Mount{mounts[0]}
		mutate(&spec, &m)
		var e errs
		spec.validate(&e, "w", m)
		if e.err() == nil {
			t.Errorf("%s accepted", name)
		}
	}
}

func TestStopSpecValidation(t *testing.T) {
	ok := []StopSpec{{Signal: SignalInt, Timeout: "60s", NoInit: true}, {Signal: SignalTerm, Timeout: "5m"}, {Signal: SignalTerm, Timeout: "1s"}}
	for _, s := range ok {
		var e errs
		s.validate(&e, "stop")
		if err := e.err(); err != nil {
			t.Errorf("%+v: %v", s, err)
		}
	}
	bad := map[string]StopSpec{
		"SIGKILL":       {Signal: "SIGKILL", Timeout: "10s"},
		"SIGQUIT":       {Signal: "SIGQUIT", Timeout: "10s"},
		"no signal":     {Timeout: "10s"},
		"no timeout":    {Signal: SignalTerm},
		"too long":      {Signal: SignalTerm, Timeout: "6m"},
		"below 1s":      {Signal: SignalTerm, Timeout: "500ms"},
		"compound unit": {Signal: SignalTerm, Timeout: "1m30s"},
	}
	for name, s := range bad {
		var e errs
		s.validate(&e, "stop")
		if e.err() == nil {
			t.Errorf("%s accepted", name)
		}
	}
}

func TestPointInTimeRestoreSpec(t *testing.T) {
	at := time.Now()
	ok := &RestoreSpec{
		Kind: BackupBaseBackup, Snapshot: "1a2b3c4d", Target: BackupTarget{Engine: EnginePostgres, Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK"},
		Destination: pitrDest(), Mode: RestoreInPlace, PointInTime: &at, WalTags: []BackupTag{"kind:wal"},
		Runtime: &RestoreRuntime{
			Image: ImageRef("127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:" + strings.Repeat("a", 64)), MountPath: "/var/lib/postgresql",
			DataDirectory: "/var/lib/postgresql/18/docker", ArchiveDirectory: "/var/lib/postgresql/launchroom-wal",
			Env: Sealed{Sealed: SealedBox(strings.Repeat("A", 80))}, Resources: Resources{CPUs: 1, MemoryMB: 512, Pids: 256},
		},
	}
	if err := ok.Validate(); err != nil {
		t.Fatal(err)
	}
	bad := *ok
	bad.Runtime = nil
	if bad.Validate() == nil {
		t.Error("base_backup restore without runtime accepted")
	}
	rt := *ok.Runtime
	rt.DataDirectory = "/srv/data"
	bad = *ok
	bad.Runtime = &rt
	if bad.Validate() == nil {
		t.Error("data directory outside the mount accepted")
	}
	dump := &RestoreSpec{Kind: BackupDatabase, Snapshot: "1a2b3c4d", Target: BackupTarget{Engine: EnginePostgres, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK"},
		Destination: pitrDest(), Mode: RestoreInPlace, PointInTime: &at}
	if dump.Validate() == nil {
		t.Error("pointInTime on a dump restore accepted")
	}
	base := &BackupSpec{Kind: BackupBaseBackup, Target: BackupTarget{Engine: EngineMySQL, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}, Destination: pitrDest()}
	if base.Validate() == nil {
		t.Error("MySQL base backup accepted")
	}
}

func TestVerifyRestoreSpec(t *testing.T) {
	res := Resources{CPUs: 1, MemoryMB: 1024, Pids: 512}
	img := ImageRef("127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:" + strings.Repeat("a", 64))
	for name, tc := range map[string]struct {
		spec VerifyRestoreSpec
		ok   bool
	}{
		"dump":           {VerifyRestoreSpec{Kind: BackupDatabase, Snapshot: "1a2b3c4d", Destination: pitrDest(), Engine: EnginePostgres, Image: img, DataPath: "/var/lib/postgresql", Resources: res}, true},
		"volume":         {VerifyRestoreSpec{Kind: BackupVolume, Snapshot: "1a2b3c4d", Destination: pitrDest(), Resources: res}, true},
		"volume+engine":  {VerifyRestoreSpec{Kind: BackupVolume, Snapshot: "1a2b3c4d", Destination: pitrDest(), Engine: EnginePostgres, Resources: res}, false},
		"control plane":  {VerifyRestoreSpec{Kind: BackupControlPlane, Snapshot: "1a2b3c4d", Destination: pitrDest(), Resources: res}, false},
		"base no ident":  {VerifyRestoreSpec{Kind: BackupBaseBackup, Snapshot: "1a2b3c4d", Destination: pitrDest(), Engine: EnginePostgres, Image: img, DataPath: "/var/lib/postgresql", DataDirectory: "/var/lib/postgresql/18/docker", Resources: res}, false},
		"base":           {VerifyRestoreSpec{Kind: BackupBaseBackup, Snapshot: "1a2b3c4d", Destination: pitrDest(), Engine: EnginePostgres, Image: img, DataPath: "/var/lib/postgresql", DataDirectory: "/var/lib/postgresql/18/docker", DatabaseUser: "app", DatabaseName: "app", Resources: res}, true},
		"base sql ident": {VerifyRestoreSpec{Kind: BackupBaseBackup, Snapshot: "1a2b3c4d", Destination: pitrDest(), Engine: EnginePostgres, Image: img, DataPath: "/var/lib/postgresql", DataDirectory: "/var/lib/postgresql/18/docker", DatabaseUser: "app'--", DatabaseName: "app", Resources: res}, false},
		"no limits":      {VerifyRestoreSpec{Kind: BackupVolume, Snapshot: "1a2b3c4d", Destination: pitrDest()}, false},
	} {
		if err := tc.spec.Validate(); (err == nil) != tc.ok {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestVerifyResultAndWalStatusValidate(t *testing.T) {
	c := &TaskCompletion{TypeMeta: NewTypeMeta(KindTaskCompletion), Attempt: 1, Outcome: OutcomeSucceeded,
		Verify: &VerifyResult{Passed: false, Checks: []VerifyCheck{{Name: "bogus"}}}}
	if c.Validate() == nil {
		t.Error("unknown check name accepted")
	}
	c.Verify.Checks = []VerifyCheck{{Name: VerifyCheckRestore, Detail: "ok"}}
	if err := c.Validate(); err != nil {
		t.Fatal(err)
	}
	var e errs
	(&WalArchiveStatus{Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK", State: WalArchiveActive, CheckedAt: time.Now(), LastSegment: "00000001000000000000000g"}).validate(&e, "w")
	if e.err() == nil {
		t.Error("lower-case segment accepted")
	}
}
