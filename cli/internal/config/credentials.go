package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"runtime"
	"time"

	"github.com/zalando/go-keyring"
)

// KeyringService is the keychain service name; the account is the URL.
const KeyringService = "launchroom"

// ErrNoToken means no token is stored for that dashboard.
var ErrNoToken = errors.New("no token stored")

// Keyring is the OS keychain (an interface so tests can fake it).
type Keyring interface {
	Get(service, user string) (string, error)
	Set(service, user, secret string) error
	Delete(service, user string) error
}

// SystemKeyring is go-keyring: macOS Keychain (`security -i`, the secret on
// stdin, never argv), Secret Service over D-Bus, Windows Credential Manager.
// Calls are bounded by a timeout so a missing/locked keychain daemon can't
// hang the CLI.
type SystemKeyring struct{}

func withTimeout[T any](f func() (T, error)) (T, error) {
	type result struct {
		v   T
		err error
	}
	ch := make(chan result, 1)
	go func() {
		v, err := f()
		ch <- result{v, err}
	}()
	select {
	case r := <-ch:
		return r.v, r.err
	case <-time.After(5 * time.Second):
		var zero T
		return zero, errors.New("the keychain did not answer")
	}
}

// Get reads a secret.
func (SystemKeyring) Get(service, user string) (string, error) {
	return withTimeout(func() (string, error) { return keyring.Get(service, user) })
}

// Set stores a secret.
func (SystemKeyring) Set(service, user, secret string) error {
	_, err := withTimeout(func() (struct{}, error) { return struct{}{}, keyring.Set(service, user, secret) })
	return err
}

// Delete removes a secret.
func (SystemKeyring) Delete(service, user string) error {
	_, err := withTimeout(func() (struct{}, error) { return struct{}{}, keyring.Delete(service, user) })
	return err
}

// KeychainUsable says whether trying the OS keychain makes sense: on Linux
// only with a D-Bus session (headless boxes and containers have none), and
// never when LAUNCHROOM_CREDENTIALS_STORE=file.
func KeychainUsable() bool {
	if os.Getenv("LAUNCHROOM_CREDENTIALS_STORE") == StoreFile {
		return false
	}
	if runtime.GOOS == "linux" || runtime.GOOS == "freebsd" {
		return os.Getenv("DBUS_SESSION_BUS_ADDRESS") != ""
	}
	return true
}

// Credentials stores tokens per dashboard URL.
type Credentials struct {
	Dir     string
	Keyring Keyring // nil: file only
}

type credentialsFile struct {
	Tokens map[string]string `json:"tokens"`
}

func (c Credentials) path() string { return filepath.Join(c.Dir, "credentials.json") }

// Save stores a token, preferring the keychain; returns the store used.
func (c Credentials) Save(host, token string) (string, error) {
	if c.Keyring != nil {
		if err := c.Keyring.Set(KeyringService, host, token); err == nil {
			// A stale file copy must not outlive a newer keychain token.
			_ = c.deleteFromFile(host)
			return StoreKeychain, nil
		}
	}
	f, err := c.readFile()
	if err != nil {
		return "", err
	}
	f.Tokens[host] = token
	b, err := json.MarshalIndent(f, "", "  ")
	if err != nil {
		return "", err
	}
	if err := WriteFileAtomic(c.path(), append(b, '\n'), 0o600); err != nil {
		return "", err
	}
	return StoreFile, nil
}

// Load returns the token for host from store (keychain or file).
func (c Credentials) Load(host, store string) (string, error) {
	if store == StoreKeychain && c.Keyring != nil {
		t, err := c.Keyring.Get(KeyringService, host)
		if err == nil && t != "" {
			return t, nil
		}
		if errors.Is(err, keyring.ErrNotFound) {
			return "", ErrNoToken
		}
		if err != nil {
			return "", fmt.Errorf("reading the token from the keychain: %w", err)
		}
	}
	f, err := c.readFile()
	if err != nil {
		return "", err
	}
	t, ok := f.Tokens[host]
	if !ok || t == "" {
		return "", ErrNoToken
	}
	return t, nil
}

// Delete forgets the token for host in both stores.
func (c Credentials) Delete(host string) error {
	if c.Keyring != nil {
		_ = c.Keyring.Delete(KeyringService, host)
	}
	return c.deleteFromFile(host)
}

func (c Credentials) deleteFromFile(host string) error {
	f, err := c.readFile()
	if err != nil {
		return err
	}
	if _, ok := f.Tokens[host]; !ok {
		return nil
	}
	delete(f.Tokens, host)
	b, err := json.MarshalIndent(f, "", "  ")
	if err != nil {
		return err
	}
	return WriteFileAtomic(c.path(), append(b, '\n'), 0o600)
}

// readFile reads credentials.json and refuses one readable by others.
func (c Credentials) readFile() (*credentialsFile, error) {
	f := &credentialsFile{Tokens: map[string]string{}}
	info, err := os.Stat(c.path())
	if errors.Is(err, fs.ErrNotExist) {
		return f, nil
	}
	if err != nil {
		return nil, err
	}
	if runtime.GOOS != "windows" && info.Mode().Perm()&0o077 != 0 {
		return nil, fmt.Errorf("%s is readable by other users (mode %04o); run `chmod 600 %s` or `launchroom login` again", c.path(), info.Mode().Perm(), c.path())
	}
	b, err := os.ReadFile(c.path()) //nolint:gosec // the CLI's own credentials file (mode checked above)
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(b, f); err != nil {
		return nil, fmt.Errorf("%s is not valid JSON", c.path())
	}
	if f.Tokens == nil {
		f.Tokens = map[string]string{}
	}
	return f, nil
}
