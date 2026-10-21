package config

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

type fakeKeyring struct {
	secrets map[string]string
	fail    bool
}

func (f *fakeKeyring) Get(_, user string) (string, error) {
	if f.fail {
		return "", errors.New("no keychain")
	}
	return f.secrets[user], nil
}

func (f *fakeKeyring) Set(_, user, secret string) error {
	if f.fail {
		return errors.New("no keychain")
	}
	f.secrets[user] = secret
	return nil
}

func (f *fakeKeyring) Delete(_, user string) error {
	delete(f.secrets, user)
	return nil
}

func TestFileStorePermissions(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("POSIX modes")
	}
	dir := filepath.Join(t.TempDir(), "launchroom")
	c := Credentials{Dir: dir}
	store, err := c.Save("https://a.example", "1|lr_pat_secret")
	if err != nil || store != StoreFile {
		t.Fatalf("Save = %s %v", store, err)
	}
	info, err := os.Stat(filepath.Join(dir, "credentials.json"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("credentials mode = %04o, want 0600", info.Mode().Perm())
	}
	if d, _ := os.Stat(dir); d.Mode().Perm() != 0o700 {
		t.Fatalf("dir mode = %04o, want 0700", d.Mode().Perm())
	}
	got, err := c.Load("https://a.example", StoreFile)
	if err != nil || got != "1|lr_pat_secret" {
		t.Fatalf("Load = %q %v", got, err)
	}
	if _, err := c.Load("https://other.example", StoreFile); !errors.Is(err, ErrNoToken) {
		t.Fatalf("missing host: %v", err)
	}
	// A credentials file others can read is refused.
	if err := os.Chmod(filepath.Join(dir, "credentials.json"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Load("https://a.example", StoreFile); err == nil {
		t.Fatal("a group/world-readable credentials file must be refused")
	}
}

func TestKeychainPreferredAndFallback(t *testing.T) {
	dir := t.TempDir()
	kr := &fakeKeyring{secrets: map[string]string{}}
	c := Credentials{Dir: dir, Keyring: kr}
	store, err := c.Save("https://a.example", "tok")
	if err != nil || store != StoreKeychain || kr.secrets["https://a.example"] != "tok" {
		t.Fatalf("keychain save: %s %v %v", store, err, kr.secrets)
	}
	if _, err := os.Stat(filepath.Join(dir, "credentials.json")); err == nil {
		t.Fatal("no file copy when the keychain works")
	}
	got, err := c.Load("https://a.example", StoreKeychain)
	if err != nil || got != "tok" {
		t.Fatalf("Load = %q %v", got, err)
	}

	kr.fail = true
	store, err = c.Save("https://b.example", "tok2")
	if err != nil || store != StoreFile {
		t.Fatalf("fallback: %s %v", store, err)
	}
	if err := c.Delete("https://b.example"); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Load("https://b.example", StoreFile); !errors.Is(err, ErrNoToken) {
		t.Fatalf("deleted: %v", err)
	}
}

func TestNormalizeURL(t *testing.T) {
	good := map[string]string{"https://lr.example.com/": "https://lr.example.com", "http://dashboard:8000": "http://dashboard:8000", " https://x.io/sub/ ": "https://x.io/sub"}
	for in, want := range good {
		got, err := NormalizeURL(in)
		if err != nil || got != want {
			t.Errorf("NormalizeURL(%q) = %q %v", in, got, err)
		}
	}
	for _, bad := range []string{"", "lr.example.com", "ftp://x", "https://user:pw@x", "https://x/?a=b", "javascript:alert(1)"} {
		if _, err := NormalizeURL(bad); err == nil {
			t.Errorf("NormalizeURL(%q) should fail", bad)
		}
	}
}

func TestConfigRoundTrip(t *testing.T) {
	dir := t.TempDir()
	c, err := Load(dir)
	if err != nil {
		t.Fatal(err)
	}
	c.Current = "https://a"
	c.Hosts["https://a"] = Host{Store: StoreFile, User: "u@example.com"}
	if err := c.Save(dir); err != nil {
		t.Fatal(err)
	}
	again, err := Load(dir)
	if err != nil || again.Current != "https://a" || again.Hosts["https://a"].User != "u@example.com" {
		t.Fatalf("round trip: %+v %v", again, err)
	}
}
