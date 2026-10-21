package state

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func validNodeState(t *testing.T) *NodeState {
	t.Helper()
	ns, err := DecodeNodeState([]byte(readFixture(t, "node-state.web-postgres.json")))
	if err != nil {
		t.Fatal(err)
	}
	return ns
}

func TestStrictDecodingGoSpecific(t *testing.T) {
	base := readFixture(t, "node-state.web-postgres.json")
	cases := map[string]string{
		"duplicate key":     strings.Replace(base, `"version": 1842,`, `"version": 1842, "version": 1843,`, 1),
		"case-folded key":   strings.Replace(base, `"serverId"`, `"serverID"`, 1),
		"trailing data":     base + `{}`,
		"fractional int":    strings.Replace(base, `"version": 1842`, `"version": 1842.5`, 1),
		"string for number": strings.Replace(base, `"version": 1842`, `"version": "1842"`, 1),
		"not an object":     `[]`,
		"invalid utf8":      strings.Replace(base, `"lr-e_7f3c2a91b0d4e5f6"`, "\"lr-e_\xff\"", 1),
	}
	for name, doc := range cases {
		t.Run(name, func(t *testing.T) {
			if doc == base {
				t.Fatal("case did not change the fixture")
			}
			if _, err := DecodeNodeState([]byte(doc)); err == nil {
				t.Fatal("accepted")
			}
		})
	}
	if _, err := DecodeNodeState(make([]byte, MaxDocumentBytes+1)); err == nil {
		t.Fatal("oversized document accepted")
	}
}

func TestNodeStateCrossReferences(t *testing.T) {
	cases := map[string]func(ns *NodeState){
		"unknown network":    func(ns *NodeState) { ns.Workloads[0].Networks[0].Network = "net_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ" },
		"unknown volume":     func(ns *NodeState) { ns.Workloads[1].Mounts[0].Volume = "vol_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ" },
		"duplicate workload": func(ns *NodeState) { ns.Workloads[1].ID = ns.Workloads[0].ID },
		"draining without drainUntil": func(ns *NodeState) {
			ns.Workloads[0].Role = RoleDraining
		},
		"drainUntil while serving": func(ns *NodeState) {
			now := time.Now()
			ns.Workloads[0].DrainUntil = &now
		},
		"health without port": func(ns *NodeState) { ns.Workloads[0].Port = nil },
		"mesh publish without port": func(ns *NodeState) {
			ns.Workloads[0].Port = nil
			ns.Workloads[0].Health = nil
			ns.Workloads[0].PublishOnMesh = &MeshPublish{Port: 31001}
		},
		"mesh port outside range": func(ns *NodeState) { ns.Workloads[0].PublishOnMesh = &MeshPublish{Port: 8080} },
		"redirect host not in hosts": func(ns *NodeState) {
			ns.Routes[0].RedirectWWWTo = "other.example.com"
		},
		"duplicate host across routes": func(ns *NodeState) {
			r := ns.Routes[0]
			r.ID = "rt_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ"
			ns.Routes = append(ns.Routes, r)
		},
		"header injection": func(ns *NodeState) {
			ns.Routes[0].Headers = map[HeaderName]string{"X-A": "b\r\nSet-Cookie: x"}
		},
		"mount on docker socket": func(ns *NodeState) {
			ns.Workloads[1].Mounts[0].Target = "/var/run/docker.sock"
		},
		"mount under proc":   func(ns *NodeState) { ns.Workloads[1].Mounts[0].Target = "/proc/1" },
		"mount with dotdot":  func(ns *NodeState) { ns.Workloads[1].Mounts[0].Target = "/data/../etc" },
		"argv with NUL":      func(ns *NodeState) { ns.Workloads[0].Args = []string{"node", "a\x00b"} },
		"argv empty program": func(ns *NodeState) { ns.Workloads[0].Args = []string{""} },
		"zero memory":        func(ns *NodeState) { ns.Workloads[0].Resources.MemoryMB = 0 },
		"timeout above interval": func(ns *NodeState) {
			ns.Workloads[0].Health.Timeout = "10s"
		},
		"enabled tailscale without login server": func(ns *NodeState) {
			ns.System.Tailscale = TailscaleSpec{Enabled: true, Image: ns.System.Caddy.Image}
		},
		"duplicate subnet": func(ns *NodeState) {
			n := ns.Networks[0]
			n.ID, n.Name = "net_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ", "lr-e_other"
			ns.Networks = append(ns.Networks, n)
		},
		"non canonical subnet": func(ns *NodeState) { ns.Networks[0].Subnet = "10.231.4.1/24" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			ns := validNodeState(t)
			mutate(ns)
			err := ns.Validate()
			if err == nil {
				t.Fatal("accepted")
			}
			var ve *ValidationError
			if !errors.As(err, &ve) {
				t.Fatalf("error type %T", err)
			}
		})
	}
}

func TestForwardAuthURL(t *testing.T) {
	ok := map[ForwardAuthURL]string{
		"https://dash.example.com/auth/preview":      "https://dash.example.com:443",
		"http://dashboard:8000/auth/preview":         "http://dashboard:8000",
		"http://127.0.0.1:8000/auth/preview":         "http://127.0.0.1:8000",
		"https://dash.example.com:8443/auth/preview": "https://dash.example.com:8443",
		"http://dashboard/auth/preview":              "http://dashboard:80",
		"https://10.0.0.1:65535/auth/preview":        "https://10.0.0.1:65535",
	}
	for u, origin := range ok {
		if err := u.Validate(); err != nil {
			t.Errorf("%s: %v", u, err)
		}
		if got := u.Origin(); got != origin {
			t.Errorf("%s: origin %q, want %q", u, got, origin)
		}
	}
	bad := map[string]ForwardAuthURL{
		"userinfo":         "https://user:pw@dash.example.com/auth/preview",
		"user only":        "https://user@dash.example.com/auth/preview",
		"query":            "https://dash.example.com/auth/preview?x=1",
		"empty query":      "https://dash.example.com/auth/preview?",
		"fragment":         "https://dash.example.com/auth/preview#x",
		"other path":       "https://dash.example.com/login",
		"path prefix":      "https://dash.example.com/x/auth/preview",
		"trailing slash":   "https://dash.example.com/auth/preview/",
		"no path":          "https://dash.example.com",
		"uppercase host":   "https://Dash.example.com/auth/preview",
		"port 0":           "https://dash.example.com:0/auth/preview",
		"port 70000":       "https://dash.example.com:70000/auth/preview",
		"leading zero":     "https://dash.example.com:0443/auth/preview",
		"bad ipv4":         "http://999.0.0.1:8000/auth/preview",
		"ipv6":             "http://[::1]:8000/auth/preview",
		"other scheme":     "ftp://dash.example.com/auth/preview",
		"trailing dot":     "https://dash.example.com./auth/preview",
		"hyphen label":     "https://-dash.example.com/auth/preview",
		"empty":            "",
		"newline":          "https://dash.example.com/auth/preview\n",
		"too long":         ForwardAuthURL("https://" + strings.Repeat("a.", 260) + "b/auth/preview"),
		"placeholder host": "https://{env.X}/auth/preview",
	}
	for name, u := range bad {
		if err := u.Validate(); err == nil {
			t.Errorf("%s: %q accepted", name, u)
		}
	}
}

func TestRouteProtectionValidation(t *testing.T) {
	ns := validNodeState(t)
	ns.Routes[0].Protection = &RouteProtection{ForwardAuth: ForwardAuth{URL: "https://dash.example.com/auth/preview", StripHeaders: []HeaderName{"X-Launchroom-Bypass"}}}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	ns.Routes[0].Protection.ForwardAuth.StripHeaders = nil
	if err := ns.Validate(); err != nil {
		t.Fatalf("no strip headers: %v", err)
	}
	many := make([]HeaderName, 9)
	for i := range many {
		many[i] = HeaderName("X-H" + string(rune('a'+i)))
	}
	bad := map[string]ForwardAuth{
		"bad url":          {URL: "https://dash.example.com/other"},
		"missing url":      {},
		"too many headers": {URL: "https://dash.example.com/auth/preview", StripHeaders: many},
		"duplicate header": {URL: "https://dash.example.com/auth/preview", StripHeaders: []HeaderName{"X-A", "X-A"}},
		"bad header name":  {URL: "https://dash.example.com/auth/preview", StripHeaders: []HeaderName{"X A"}},
	}
	for name, fa := range bad {
		ns := validNodeState(t)
		ns.Routes[0].Protection = &RouteProtection{ForwardAuth: fa}
		if err := ns.Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	// Strict decoding rejects unknown members inside protection.
	base := readFixture(t, "node-state.preview.json")
	doc := strings.Replace(base, `"forwardAuth": {`, `"forwardAuth": {"copyHeaders": ["X-User"], `, 1)
	if doc == base {
		t.Fatal("case did not change the fixture")
	}
	if _, err := DecodeNodeState([]byte(doc)); err == nil {
		t.Fatal("unknown forwardAuth member accepted")
	}
}

func TestNodeStateRoundTrip(t *testing.T) {
	ns := validNodeState(t)
	data, err := Encode(ns)
	if err != nil {
		t.Fatal(err)
	}
	again, err := DecodeNodeState(data)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(ns, again) {
		t.Fatal("round trip changed the document")
	}
}

func TestTaskUnion(t *testing.T) {
	lease, err := Decode[TaskLease]([]byte(readFixture(t, "task-lease.build.json")))
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := lease.Tasks[0].Spec.(*BuildSpec); !ok {
		t.Fatalf("spec type %T", lease.Tasks[0].Spec)
	}

	base := readFixture(t, "task-lease.build.json")
	bad := map[string]string{
		"unknown type":        strings.Replace(base, `"type": "build"`, `"type": "shell"`, 1),
		"type/spec mismatch":  strings.Replace(base, `"type": "build"`, `"type": "logs"`, 1),
		"unknown spec field":  strings.Replace(base, `"cacheKey"`, `"script": "rm -rf /", "cacheKey"`, 1),
		"plan with push":      strings.Replace(base, `"type": "build"`, `"type": "plan"`, 1),
		"attempt zero":        strings.Replace(base, `"attempt": 1`, `"attempt": 0`, 1),
		"dockerfile + preset": strings.Replace(base, `"kind": "auto"`, `"kind": "dockerfile"`, 1),
		"wrong push repo":     strings.Replace(base, `apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2`, `apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n3`, 1),
		"bad railpack env":    strings.Replace(base, `"RAILPACK_NODE_VERSION"`, `"NODE_VERSION"`, 1),
		"file clone url":      strings.Replace(base, `https://github.com/example/shop.git`, `file:///etc`, 1),
	}
	for name, doc := range bad {
		t.Run(name, func(t *testing.T) {
			if doc == base {
				t.Fatal("case did not change the fixture")
			}
			if _, err := Decode[TaskLease]([]byte(doc)); err == nil {
				t.Fatal("accepted")
			}
		})
	}

	// Every task type round-trips through the union.
	for typ, spec := range SpecTypes() {
		if spec.TaskType() != typ {
			t.Errorf("%s spec reports %s", typ, spec.TaskType())
		}
		task := Task{ID: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Type: typ, Attempt: 1, Spec: spec}
		data, err := Encode(task)
		if err != nil {
			t.Fatal(err)
		}
		var back Task
		if err := DecodeStrict(data, &back); err != nil {
			t.Fatalf("%s: %v", typ, err)
		}
		if reflect.TypeOf(back.Spec) != reflect.TypeOf(spec) {
			t.Fatalf("%s decoded as %T", typ, back.Spec)
		}
	}
}

func TestSpecValidation(t *testing.T) {
	sealed := Sealed{Sealed: SealedBox(base64.StdEncoding.EncodeToString(make([]byte, 64)))}
	dest := BackupDestination{Kind: DestinationS3, Endpoint: "https://s3.example.com", Bucket: "backups", Credentials: sealed}
	res := Resources{CPUs: 1, MemoryMB: 256, Pids: 128}
	img := ImageRef("127.0.0.1:5000/apps/x@sha256:" + strings.Repeat("a", 64))
	ok := []TaskSpec{
		&RunOnceSpec{Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Image: img, Args: []string{"php", "artisan", "migrate", "--force"}, Env: sealed, Network: NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Name: "lr-e_01j9z8x7w6v5t4s3r2q1p0n9mk", Subnet: "10.231.4.0/24"}, Timeout: "10m", Resources: res},
		&BackupSpec{Kind: BackupDatabase, Target: BackupTarget{Engine: EnginePostgres, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}, Destination: dest, Tags: []BackupTag{"kind:database"}},
		&BackupSpec{Kind: BackupVolume, Target: BackupTarget{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Consistency: ConsistencyPause}, Destination: dest, Tags: []BackupTag{}},
		&RestoreSpec{Kind: BackupDatabase, Snapshot: "1a2b3c4d", Target: BackupTarget{Engine: EnginePostgres, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}, Destination: dest, Mode: RestoreNewService},
		&BackupMaintenanceSpec{Operation: MaintenanceForget, Destination: dest, Retention: &Retention{KeepDaily: 7}},
		&BackupMaintenanceSpec{Operation: MaintenanceCheck, Destination: dest, ReadDataSubsetPercent: 5},
		&LogsSpec{Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Tail: 100, Follow: true},
		&SelfUpdateSpec{Version: "v0.2.0", Image: ImageRef("ghcr.io/vantezzen/launchroomd@sha256:" + strings.Repeat("b", 64))},
		&SelfUpdateSpec{Version: "0.3.0", Image: ImageRef("ghcr.io/vantezzen/launchroomd@sha256:" + strings.Repeat("b", 64)), Scope: UpdateControlPlane},
		&BackupSnapshotsSpec{Destination: dest, Tags: []BackupTag{"kind:database", "service:svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}},
		&BackupSpec{Kind: BackupDatabase, Target: BackupTarget{Engine: EngineValkey, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}, Tags: []BackupTag{},
			Destination: BackupDestination{Kind: DestinationS3, Endpoint: "http://minio:9000", Bucket: "backups", Region: "us-east-1", Prefix: "lr/team", Credentials: sealed}},
	}
	for _, s := range ok {
		if err := s.Validate(); err != nil {
			t.Errorf("%s: %v", s.TaskType(), err)
		}
	}
	bad := map[string]TaskSpec{
		"run_once without args":       &RunOnceSpec{Image: img, Env: sealed, Network: NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Name: "lr-e_01j9z8x7w6v5t4s3r2q1p0n9mk", Subnet: "10.231.4.0/24"}, Timeout: "1m", Resources: res},
		"database backup with volume": &BackupSpec{Kind: BackupDatabase, Target: BackupTarget{Engine: EnginePostgres, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}, Destination: dest},
		"s3 without bucket":           &BackupSpec{Kind: BackupControlPlane, Destination: BackupDestination{Kind: DestinationS3, Endpoint: "https://s3.example.com", Credentials: sealed}},
		"endpoint with path":          &BackupSpec{Kind: BackupControlPlane, Destination: BackupDestination{Kind: DestinationS3, Endpoint: "https://s3.example.com/bucket", Bucket: "b-1", Credentials: sealed}},
		"endpoint with credentials":   &BackupSpec{Kind: BackupControlPlane, Destination: BackupDestination{Kind: DestinationS3, Endpoint: "https://user:pw@s3.example.com", Bucket: "b-1", Credentials: sealed}},
		"snapshots bad tag":           &BackupSnapshotsSpec{Destination: dest, Tags: []BackupTag{"no colon"}},
		"forget without retention":    &BackupMaintenanceSpec{Operation: MaintenanceForget, Destination: dest},
		"control plane restore":       &RestoreSpec{Kind: BackupControlPlane, Snapshot: "1a2b3c4d", Destination: dest, Mode: RestoreInPlace},
		"prefix traversal":            &BackupSpec{Kind: BackupControlPlane, Destination: BackupDestination{Kind: DestinationLocal, Prefix: "../etc", Credentials: sealed}},
		"self update tag":             &SelfUpdateSpec{Version: "1.0.0", Image: "ghcr.io/vantezzen/launchroomd:latest"},
		"self update bad scope":       &SelfUpdateSpec{Version: "1.0.0", Image: ImageRef("ghcr.io/vantezzen/launchroomd@sha256:" + strings.Repeat("b", 64)), Scope: "host"},
	}
	for name, s := range bad {
		if err := s.Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestJoinTokenSchemaMatchesGo(t *testing.T) {
	f, ok := reflect.TypeFor[EnrollRequest]().FieldByName("Token")
	if !ok {
		t.Fatal("no Token field")
	}
	tag := strings.ReplaceAll(f.Tag.Get("jsonschema"), `\,`, ",")
	if !strings.Contains(tag, "pattern="+reJoinToken.String()) {
		t.Fatalf("jsonschema tag %q does not carry %s", tag, reJoinToken)
	}
}

func TestEnrollRequestRevealsTokenOnlyOnWire(t *testing.T) {
	r, err := Decode[EnrollRequest]([]byte(readFixture(t, "enroll-request.json")))
	if err != nil {
		t.Fatal(err)
	}
	if got := r.Token.String(); strings.Contains(got, "lr_jt_") {
		t.Fatal("token leaked through String")
	}
	data, err := Encode(r)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(data), r.Token.Reveal()) {
		t.Fatal("token missing from wire form")
	}
}

// ---- store ----

type keyVerifier struct {
	pub ed25519.PublicKey
}

func (k keyVerifier) Verify(header string, body []byte) (string, error) {
	sig, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(header, "kid=test, sig="))
	if err != nil || !ed25519.Verify(k.pub, body, sig) {
		return "", errors.New("bad signature")
	}
	return "test", nil
}

func sign(key ed25519.PrivateKey, body []byte) string {
	return "kid=test, sig=" + base64.StdEncoding.EncodeToString(ed25519.Sign(key, body))
}

func signedVersion(t *testing.T, key ed25519.PrivateKey, version int64) *SignedState {
	t.Helper()
	ns := validNodeState(t)
	ns.Version = version
	raw, err := Encode(ns)
	if err != nil {
		t.Fatal(err)
	}
	return &SignedState{Raw: raw, Signature: sign(key, raw)}
}

func TestStore(t *testing.T) {
	pub, key, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	v := keyVerifier{pub}
	server := ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	path := filepath.Join(t.TempDir(), "state.json")

	s := NewStore(path, v, server)
	if err := s.Load(); err != nil {
		t.Fatalf("missing file should not be an error: %v", err)
	}
	if s.Version() != 0 || s.Current() != nil {
		t.Fatal("empty store has state")
	}

	if err := s.Accept(signedVersion(t, key, 10)); err != nil {
		t.Fatal(err)
	}
	for _, stale := range []int64{10, 9} {
		if err := s.Accept(signedVersion(t, key, stale)); !errors.Is(err, ErrStaleVersion) {
			t.Fatalf("version %d: %v", stale, err)
		}
	}
	unsigned := signedVersion(t, key, 11)
	unsigned.Signature = ""
	if err := s.Accept(unsigned); err == nil {
		t.Fatal("unsigned state accepted")
	}
	_, otherKey, _ := ed25519.GenerateKey(rand.Reader)
	if err := s.Accept(signedVersion(t, otherKey, 11)); err == nil {
		t.Fatal("state signed by unknown key accepted")
	}

	fi, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if fi.Mode().Perm() != 0o600 {
		t.Fatalf("state.json perm %v", fi.Mode().Perm())
	}

	// Restart: reload and keep enforcing monotonicity.
	s2 := NewStore(path, v, server)
	if err := s2.Load(); err != nil {
		t.Fatal(err)
	}
	if s2.Version() != 10 {
		t.Fatalf("reloaded version %d", s2.Version())
	}
	if err := s2.Accept(signedVersion(t, key, 10)); !errors.Is(err, ErrStaleVersion) {
		t.Fatalf("stale after reload: %v", err)
	}

	// Wrong server id is rejected on load.
	if err := NewStore(path, v, "srv_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ").Load(); err == nil {
		t.Fatal("state for another server accepted")
	}

	// Tampering with the persisted body is detected and the file is not used.
	data, err := os.ReadFile(path) //nolint:gosec // test path
	if err != nil {
		t.Fatal(err)
	}
	tampered := strings.Replace(string(data), `"body":"`, `"body":"AAAA`, 1)
	if err := os.WriteFile(path, []byte(tampered), 0o600); err != nil {
		t.Fatal(err)
	}
	s3 := NewStore(path, v, server)
	if err := s3.Load(); err == nil {
		t.Fatal("tampered state accepted")
	}
	if s3.Current() != nil {
		t.Fatal("tampered state used")
	}
}
