package config

import (
	"errors"
	"io/fs"
	"log/slog"
	"strings"
	"testing"
)

const (
	cpKey = "ed25519:Kay64UG8yvCyLhqU000LxzYeUm0L/hLIl5S8kyKWbdc="
	token = "lr_jt_Zm9vYmFyYmF6cXV4cXV1eA"
)

func envOf(m map[string]string) Lookup {
	return func(k string) (string, bool) { v, ok := m[k]; return v, ok }
}

func noFile(p string) ([]byte, error) {
	if p == "/data/cp-key" {
		return []byte(cpKey + "\n"), nil
	}
	return nil, errors.New("no file")
}

func TestLoadDefaultsAndEnv(t *testing.T) {
	cfg, err := Load(nil, envOf(map[string]string{
		EnvControlPlaneURL: "https://dash.example.com/",
		EnvCPKey:           cpKey,
		EnvJoinToken:       token,
	}), noFile)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ControlPlaneURL.String() != "https://dash.example.com" {
		t.Errorf("url = %s", cfg.ControlPlaneURL)
	}
	if cfg.DataDir != "/data" || cfg.KeysDir() != "/data/keys" {
		t.Errorf("data dir = %s", cfg.DataDir)
	}
	if cfg.JoinToken.Reveal() != token || len(cfg.PinnedCPKey) != 32 {
		t.Error("token or key not loaded")
	}
	if cfg.LogLevel != slog.LevelInfo {
		t.Errorf("level = %v", cfg.LogLevel)
	}
	if cfg.Firewall != "enforce" || len(cfg.FirewallAllowPrivate) != 0 {
		t.Errorf("firewall = %q %v", cfg.Firewall, cfg.FirewallAllowPrivate)
	}
}

func TestLoadHostHints(t *testing.T) {
	// The installer's macOS hints (D53): a known OS is kept, anything else ignored.
	for v, want := range map[string]string{"darwin": "darwin", " Darwin ": "darwin", "linux": "linux", "": "", "windows": "", "$(x)": ""} {
		cfg, err := Load(nil, envOf(map[string]string{EnvControlPlaneURL: "https://dash.example.com", EnvHostOS: v, EnvHostModel: " Mac mini "}), noFile)
		if err != nil {
			t.Fatal(err)
		}
		if string(cfg.HostOS) != want || cfg.HostModel != "Mac mini" {
			t.Errorf("LR_HOST_OS=%q: %q %q", v, cfg.HostOS, cfg.HostModel)
		}
	}
}

func TestLoadFirewall(t *testing.T) {
	cfg, err := Load(nil, envOf(map[string]string{
		EnvControlPlaneURL: "https://dash.example.com", EnvFirewall: "OFF", EnvFirewallAllowPrivate: "192.168.1.0/24, 10.20.0.0/16",
	}), noFile)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Firewall != "off" || len(cfg.FirewallAllowPrivate) != 2 || cfg.FirewallAllowPrivate[1].String() != "10.20.0.0/16" {
		t.Fatalf("firewall = %q %v", cfg.Firewall, cfg.FirewallAllowPrivate)
	}
}

func TestFlagsOverrideEnv(t *testing.T) {
	cfg, err := Load([]string{"--control-plane-url", "http://cp.localhost:8080", "--data-dir", "/srv/daemon", "--log-level", "debug"},
		envOf(map[string]string{EnvControlPlaneURL: "https://ignored.example.com", EnvDataDir: "/ignored"}), noFile)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ControlPlaneURL.Host != "cp.localhost:8080" || cfg.DataDir != "/srv/daemon" || cfg.LogLevel != slog.LevelDebug {
		t.Errorf("cfg = %+v", cfg)
	}
}

func TestJoinTokenFromFile(t *testing.T) {
	read := func(p string) ([]byte, error) {
		if p != "/run/secrets/join" {
			t.Fatalf("read %s", p)
		}
		return []byte(token + "\n"), nil
	}
	cfg, err := Load(nil, envOf(map[string]string{
		EnvControlPlaneURL: "https://dash.example.com", EnvCPKey: cpKey, EnvJoinTokenFile: "/run/secrets/join",
	}), read)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.JoinToken.Reveal() != token {
		t.Fatal("token not read from file")
	}
}

func TestCPKeyFromFile(t *testing.T) {
	read := func(p string) ([]byte, error) {
		switch p {
		case "/data/cp-key":
			return []byte(cpKey + "\n"), nil
		case "/run/secrets/join":
			return []byte(token), nil
		}
		return nil, fs.ErrNotExist
	}
	cfg, err := Load(nil, envOf(map[string]string{
		EnvControlPlaneURL: "https://dash.example.com", EnvCPKeyFile: "/data/cp-key", EnvJoinTokenFile: "/run/secrets/join",
	}), read)
	if err != nil {
		t.Fatal(err)
	}
	if len(cfg.PinnedCPKey) != 32 {
		t.Fatal("key not read from file")
	}
	// Env and file may both be set if they agree (dev: .env + data volume).
	if _, err := Load(nil, envOf(map[string]string{EnvControlPlaneURL: "https://dash.example.com", EnvCPKey: cpKey, EnvCPKeyFile: "/data/cp-key"}), read); err != nil {
		t.Fatal(err)
	}
	// Env set, file missing → env.
	cfg, err = Load(nil, envOf(map[string]string{EnvControlPlaneURL: "https://dash.example.com", EnvCPKey: cpKey, EnvCPKeyFile: "/data/missing"}), read)
	if err != nil || len(cfg.PinnedCPKey) != 32 {
		t.Fatalf("env fallback: %v", err)
	}
	// A missing key file is "unset": fine once enrolled (no token) ...
	cfg, err = Load(nil, envOf(map[string]string{EnvControlPlaneURL: "https://dash.example.com", EnvCPKeyFile: "/data/missing"}), read)
	if err != nil || cfg.PinnedCPKey != nil {
		t.Fatalf("cfg=%+v err=%v", cfg, err)
	}
	// ... but enrolling without it is refused.
	_, err = Load(nil, envOf(map[string]string{
		EnvControlPlaneURL: "https://dash.example.com", EnvCPKeyFile: "/data/missing", EnvJoinTokenFile: "/run/secrets/join",
	}), read)
	if err == nil || !strings.Contains(err.Error(), EnvCPKeyFile) {
		t.Fatalf("err = %v", err)
	}
}

func TestIngressDefaults(t *testing.T) {
	cfg, err := Load(nil, envOf(map[string]string{EnvControlPlaneURL: "https://dash.example.com"}), noFile)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.AskAddr.String() != DefaultAskAddr || cfg.AccessLogAddr.String() != DefaultAccessLogAddr || cfg.HTTPSRedirectPort != 443 {
		t.Fatalf("cfg = %+v", cfg)
	}
}

func TestLoadErrors(t *testing.T) {
	base := map[string]string{EnvControlPlaneURL: "https://dash.example.com"}
	with := func(kv ...string) map[string]string {
		m := map[string]string{}
		for k, v := range base {
			m[k] = v
		}
		for i := 0; i < len(kv); i += 2 {
			m[kv[i]] = kv[i+1]
		}
		return m
	}
	cases := []struct {
		name string
		args []string
		env  map[string]string
		want string
	}{
		{"missing url", nil, map[string]string{}, "control-plane URL is required"},
		{"ftp url", nil, with(EnvControlPlaneURL, "ftp://x"), "must be http(s)"},
		{"url with creds", nil, with(EnvControlPlaneURL, "https://u:p@x"), "credentials"},
		{"url with query", nil, with(EnvControlPlaneURL, "https://x/?a=b"), "query"},
		{"relative data dir", nil, with(EnvDataDir, "data"), "absolute"},
		{"bad cp key", nil, with(EnvCPKey, "ed25519:nope"), EnvCPKey},
		{"token without cp key", nil, with(EnvJoinToken, token), "requires " + EnvCPKey},
		{"token and file", nil, with(EnvCPKey, cpKey, EnvJoinToken, token, EnvJoinTokenFile, "/x"), "only one"},
		{"bad token", nil, with(EnvCPKey, cpKey, EnvJoinToken, "short"), "invalid format"},
		{"relative token file", nil, with(EnvCPKey, cpKey, EnvJoinTokenFile, "token.txt"), "absolute"},
		{"unreadable token file", nil, with(EnvCPKey, cpKey, EnvJoinTokenFile, "/missing"), "read"},
		{"token as flag", []string{"--join-token", token}, with(EnvCPKey, cpKey), "only accepted via"},
		{"stray args", []string{"extra"}, base, "unexpected arguments"},
		{"bad log level", []string{"--log-level", "loud"}, base, "log level"},
		{"key and differing key file", nil, with(EnvCPKey, "ed25519:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", EnvCPKeyFile, "/data/cp-key"), "different keys"},
		{"relative key file", nil, with(EnvCPKeyFile, "cp-key"), "absolute"},
		{"unreadable key file", nil, with(EnvCPKeyFile, "/x"), "read " + EnvCPKeyFile},
		{"public ask addr", nil, with(EnvAskAddr, "0.0.0.0:2021"), "loopback"},
		{"public access log addr", nil, with(EnvAccessLogAddr, "0.0.0.0:2022"), "loopback"},
		{"bad https port", nil, with(EnvHTTPSRedirectPort, "0"), EnvHTTPSRedirectPort},
		{"bad firewall mode", nil, with(EnvFirewall, "permissive"), EnvFirewall},
		{"firewall allow-list not a cidr", nil, with(EnvFirewallAllowPrivate, "192.168.1.1"), EnvFirewallAllowPrivate},
		{"firewall allow-list everything", nil, with(EnvFirewallAllowPrivate, "0.0.0.0/0"), EnvFirewallAllowPrivate},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := Load(tc.args, envOf(tc.env), noFile)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
			if strings.Contains(err.Error(), token) {
				t.Fatal("error message leaks the token")
			}
		})
	}
}

func TestBackupConfig(t *testing.T) {
	cfg, err := Load(nil, envOf(map[string]string{
		EnvControlPlaneURL: "https://dash.example.com", EnvResticBinary: "/opt/restic", EnvBackupInsecureHosts: " MinIO , s3-dev ",
	}), noFile)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ResticBinary != "/opt/restic" || strings.Join(cfg.BackupInsecureHosts, ",") != "minio,s3-dev" {
		t.Fatalf("cfg = %q %q", cfg.ResticBinary, cfg.BackupInsecureHosts)
	}
	if _, err := Load(nil, envOf(map[string]string{EnvControlPlaneURL: "https://dash.example.com", EnvResticBinary: "restic"}), noFile); err == nil {
		t.Fatal("relative restic path accepted")
	}
}

func TestAllowedLocalUpstreams(t *testing.T) {
	base := map[string]string{EnvControlPlaneURL: "https://dash.example.com"}
	with := func(v string) map[string]string {
		m := map[string]string{EnvAllowedLocalUpstreams: v}
		for k, x := range base {
			m[k] = x
		}
		return m
	}
	cfg, err := Load(nil, envOf(base), noFile)
	if err != nil || len(cfg.AllowedLocalUpstreams) != 0 {
		t.Fatalf("default: %v %v", cfg.AllowedLocalUpstreams, err)
	}
	cfg, err = Load(nil, envOf(with(" 127.0.0.1:8000, [::1]:9000 ")), noFile)
	if err != nil || len(cfg.AllowedLocalUpstreams) != 2 || cfg.AllowedLocalUpstreams[0].String() != "127.0.0.1:8000" {
		t.Fatalf("parsed: %v %v", cfg.AllowedLocalUpstreams, err)
	}
	for _, bad := range []string{"localhost:8000", "127.0.0.1", "127.0.0.1:0", "0.0.0.0:80", "127.0.0.1:8000,nope"} {
		if _, err := Load(nil, envOf(with(bad)), noFile); err == nil || !strings.Contains(err.Error(), EnvAllowedLocalUpstreams) {
			t.Errorf("%q: err = %v", bad, err)
		}
	}
}

func TestLoadDataTransfers(t *testing.T) {
	base := map[string]string{EnvControlPlaneURL: "https://dash.example.com"}
	cfg, err := Load(nil, envOf(base), noFile)
	if err != nil || !cfg.DataTransfers {
		t.Fatalf("default: %v %v", cfg.DataTransfers, err)
	}
	base[EnvDataTransfers] = "off"
	if cfg, err = Load(nil, envOf(base), noFile); err != nil || cfg.DataTransfers {
		t.Fatalf("off: %v %v", cfg.DataTransfers, err)
	}
	base[EnvDataTransfers] = "maybe"
	if _, err = Load(nil, envOf(base), noFile); err == nil {
		t.Fatal("invalid value accepted")
	}
}

func TestLoadPublicIP(t *testing.T) {
	base := map[string]string{EnvControlPlaneURL: "https://dash.example.com"}
	cfg, err := Load(nil, envOf(base), noFile)
	if err != nil || cfg.PublicIPDetectOff || cfg.PublicIPs != nil {
		t.Fatalf("defaults: %+v %v", cfg.PublicIPs, err)
	}
	with := func(k, v string) map[string]string {
		m := map[string]string{EnvControlPlaneURL: "https://dash.example.com"}
		m[k] = v
		return m
	}
	cfg, err = Load(nil, envOf(with(EnvPublicIPDetect, "OFF")), noFile)
	if err != nil || !cfg.PublicIPDetectOff {
		t.Fatalf("off: %v", err)
	}
	cfg, err = Load(nil, envOf(with(EnvPublicIPs, "93.184.216.34,2a01:4f8::1")), noFile)
	if err != nil || cfg.PublicIPs == nil || cfg.PublicIPs.IPv4 != "93.184.216.34" || cfg.PublicIPs.IPv6 != "2a01:4f8::1" {
		t.Fatalf("configured: %+v %v", cfg.PublicIPs, err)
	}
	for k, v := range map[string]string{EnvPublicIPDetect: "maybe", EnvPublicIPs: "172.18.0.1"} {
		if _, err := Load(nil, envOf(with(k, v)), noFile); err == nil || !strings.Contains(err.Error(), k) {
			t.Errorf("%s=%s: %v", k, v, err)
		}
	}
}
