package state

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/rsa"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"
)

func pubKey(t *testing.T, k any) SSHPublicKey {
	t.Helper()
	pk, err := ssh.NewPublicKey(k)
	if err != nil {
		t.Fatal(err)
	}
	return SSHPublicKey(strings.TrimSpace(string(ssh.MarshalAuthorizedKey(pk))))
}

func TestAccessSpecValidate(t *testing.T) {
	edPub, _, _ := ed25519.GenerateKey(rand.Reader)
	ed := pubKey(t, edPub)
	sealed := &Sealed{Sealed: SealedBox(strings.Repeat("A", 96))}
	ssh := func() *AccessSpec {
		return &AccessSpec{Access: "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1", Kind: AccessSSH, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8", User: "lr-abcdef0123", AuthorizedKeys: []SSHPublicKey{ed}, AllowFrom: []AccessPrefix{"203.0.113.7/32"}, IdleTimeout: "15m"}
	}
	db := func() *AccessSpec {
		return &AccessSpec{Access: "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1", Kind: AccessDatabase, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8", Engine: EnginePostgres, User: "lr_tmp_abcdef012345", Password: sealed, AllowFrom: []AccessPrefix{"2001:db8::/48"}, Hosts: []AccessHost{"203.0.113.1", "db.example.com"}, IdleTimeout: "5m", AllowWrites: true}
	}
	if err := ssh().Validate(); err != nil {
		t.Fatalf("ssh: %v", err)
	}
	if err := db().Validate(); err != nil {
		t.Fatalf("db: %v", err)
	}
	bad := map[string]func(*AccessSpec){
		"ssh with keys and password":  func(s *AccessSpec) { s.Password = sealed },
		"ssh without credentials":     func(s *AccessSpec) { s.AuthorizedKeys = nil },
		"ssh with a database role":    func(s *AccessSpec) { s.User = "lr_tmp_abcdef012345" },
		"ssh with writes":             func(s *AccessSpec) { s.AllowWrites = true },
		"user chosen by the user":     func(s *AccessSpec) { s.User = "root" },
		"no allow-list":               func(s *AccessSpec) { s.AllowFrom = nil },
		"everyone":                    func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"0.0.0.0/0"} },
		"wider than /8":               func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"10.0.0.0/7"} },
		"metadata":                    func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"169.254.169.254/32"} },
		"multicast":                   func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"224.0.0.1/32"} },
		"non-canonical":               func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"203.0.113.7/24"} },
		"ipv6 everyone":               func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"::/0"} },
		"mapped ipv4":                 func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"::ffff:203.0.113.7/128"} },
		"duplicate":                   func(s *AccessSpec) { s.AllowFrom = []AccessPrefix{"203.0.113.7/32", "203.0.113.7/32"} },
		"idle too short":              func(s *AccessSpec) { s.IdleTimeout = "1m" },
		"idle too long":               func(s *AccessSpec) { s.IdleTimeout = "2h" },
		"key with a comment smuggled": func(s *AccessSpec) { s.AuthorizedKeys = []SSHPublicKey{ed + " evil\nssh-rsa AAAA"} },
		"key type mismatch": func(s *AccessSpec) {
			s.AuthorizedKeys = []SSHPublicKey{SSHPublicKey("ecdsa-sha2-nistp256" + strings.TrimPrefix(string(ed), "ssh-ed25519"))}
		},
		"host with a path": func(s *AccessSpec) { s.Hosts = []AccessHost{"a.example.com/x"} },
	}
	for name, mut := range bad {
		s := ssh()
		mut(s)
		if err := s.Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	for name, mut := range map[string]func(*AccessSpec){
		"mysql":         func(s *AccessSpec) { s.Engine = EngineMySQL },
		"no password":   func(s *AccessSpec) { s.Password = nil },
		"keys for a db": func(s *AccessSpec) { s.AuthorizedKeys = []SSHPublicKey{ed} },
		"ssh user":      func(s *AccessSpec) { s.User = "lr-abcdef0123" },
		"no engine":     func(s *AccessSpec) { s.Engine = "" },
	} {
		s := db()
		mut(s)
		if err := s.Validate(); err == nil {
			t.Errorf("db %s: accepted", name)
		}
	}
}

// Volume access (D67): an SSH user, keys or a password, the volume and a
// digest-pinned helper image; writes allowed (the mount's mode).
func TestAccessSpecValidateVolume(t *testing.T) {
	edPub, _, _ := ed25519.GenerateKey(rand.Reader)
	ed := pubKey(t, edPub)
	vol := func() *AccessSpec {
		return &AccessSpec{
			Access: "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1", Kind: AccessVolume, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8", User: "lr-abcdef0123",
			AuthorizedKeys: []SSHPublicKey{ed}, AllowFrom: []AccessPrefix{"203.0.113.7/32"}, IdleTimeout: "15m", AllowWrites: true,
			Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1", Image: ImageRef("docker.io/library/busybox@sha256:" + strings.Repeat("a", 64)),
		}
	}
	if err := vol().Validate(); err != nil {
		t.Fatalf("volume: %v", err)
	}
	for name, mut := range map[string]func(*AccessSpec){
		"no volume":         func(s *AccessSpec) { s.Volume = "" },
		"bad volume":        func(s *AccessSpec) { s.Volume = "lr-system-caddy-data" },
		"no image":          func(s *AccessSpec) { s.Image = "" },
		"tagged image":      func(s *AccessSpec) { s.Image = "docker.io/library/busybox:latest" },
		"database role":     func(s *AccessSpec) { s.User = "lr_tmp_abcdef012345" },
		"an engine":         func(s *AccessSpec) { s.Engine = EnginePostgres },
		"no credentials":    func(s *AccessSpec) { s.AuthorizedKeys = nil },
		"certificate hosts": func(s *AccessSpec) { s.Hosts = []AccessHost{"203.0.113.1/x"} },
	} {
		s := vol()
		mut(s)
		if err := s.Validate(); err == nil {
			t.Errorf("volume %s: accepted", name)
		}
	}
	// Volume fields belong to volume access only.
	ssh := vol()
	ssh.Kind, ssh.AllowWrites = AccessSSH, false
	if err := ssh.Validate(); err == nil || !strings.Contains(err.Error(), "only valid for volume access") {
		t.Errorf("ssh with a volume: %v", err)
	}
	st := &TaskHeartbeat{TypeMeta: NewTypeMeta(KindHeartbeat), Attempt: 1, Access: &AccessStatus{Port: 40123, Owner: &FileOwner{UID: 999, GID: 999}}}
	if err := st.Validate(); err != nil {
		t.Fatal(err)
	}
	st.Access.Owner.UID = -1
	if err := st.Validate(); err == nil {
		t.Fatal("negative owner accepted")
	}
}

func TestSSHPublicKeyRefusesShortRSA(t *testing.T) {
	k, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatal(err)
	}
	if err := pubKey(t, &k.PublicKey).Validate(); err == nil || !strings.Contains(err.Error(), "3072") {
		t.Fatalf("2048-bit RSA: %v", err)
	}
}

func TestAccessStatusValidate(t *testing.T) {
	ok := &TaskHeartbeat{TypeMeta: NewTypeMeta(KindHeartbeat), Attempt: 1, Access: &AccessStatus{
		Port: 40123, HostKey: &AccessHostKey{Type: "ssh-ed25519", SHA256: "SHA256:" + strings.Repeat("a", 43), PublicKey: "ssh-ed25519 AAAA"},
		PrivateAddresses: []IPAddr{"192.168.1.20"}, Events: []AccessEvent{{At: mustTime("2026-10-25T10:00:00Z"), Type: AccessEventConnected, Remote: "203.0.113.7", Method: AccessMethodPublicKey, Channel: AccessChannelSFTP}},
	}}
	if err := ok.Validate(); err != nil {
		t.Fatal(err)
	}
	ok.Access.Port = 80
	ok.Access.Events[0].Channel = "tunnel"
	if err := ok.Validate(); err == nil {
		t.Fatal("bad status accepted")
	}
}

func mustTime(s string) time.Time {
	t, err := time.Parse(time.RFC3339, s)
	if err != nil {
		panic(err)
	}
	return t
}
