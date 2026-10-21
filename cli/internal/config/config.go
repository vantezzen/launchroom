// Package config keeps the CLI's global settings (current dashboard URL)
// and API tokens: in the OS keychain when there is one, else in a 0600
// file (docs/16-api-and-cli.md §3.4).
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

// Store names where a token lives.
const (
	StoreKeychain = "keychain"
	StoreFile     = "file"
)

// Host is what the CLI remembers about one dashboard.
type Host struct {
	Store string `json:"store"`
	User  string `json:"user,omitempty"`
	Team  string `json:"team,omitempty"`
}

// Config is config.json.
type Config struct {
	Current string          `json:"current,omitempty"`
	Hosts   map[string]Host `json:"hosts,omitempty"`
}

// Dir is $XDG_CONFIG_HOME/launchroom (or the OS default config dir).
func Dir() (string, error) {
	if d := os.Getenv("XDG_CONFIG_HOME"); d != "" {
		return filepath.Join(d, "launchroom"), nil
	}
	if runtime.GOOS != "windows" {
		home, err := os.UserHomeDir()
		if err != nil {
			return "", err
		}
		return filepath.Join(home, ".config", "launchroom"), nil
	}
	d, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(d, "launchroom"), nil
}

// NormalizeURL checks a dashboard URL and returns it without trailing slash.
func NormalizeURL(raw string) (string, error) {
	u, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" {
		return "", fmt.Errorf("%q is not a dashboard URL (expected https://launchroom.example.com)", raw)
	}
	if u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return "", fmt.Errorf("%q: the dashboard URL must not contain credentials, a query or a fragment", raw)
	}
	return strings.TrimRight(u.Scheme+"://"+u.Host+u.Path, "/"), nil
}

// Load reads config.json from dir (empty config when missing).
func Load(dir string) (*Config, error) {
	c := &Config{Hosts: map[string]Host{}}
	b, err := os.ReadFile(filepath.Join(dir, "config.json")) //nolint:gosec // the CLI's own config directory
	if errors.Is(err, fs.ErrNotExist) {
		return c, nil
	}
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(b, c); err != nil {
		return nil, fmt.Errorf("%s is not valid JSON: %w", filepath.Join(dir, "config.json"), err)
	}
	if c.Hosts == nil {
		c.Hosts = map[string]Host{}
	}
	return c, nil
}

// Save writes config.json (0600, directory 0700).
func (c *Config) Save(dir string) error {
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	return WriteFileAtomic(filepath.Join(dir, "config.json"), append(b, '\n'), 0o600)
}

// WriteFileAtomic writes via a temp file in the same directory and rename,
// creating the directory with 0700.
func WriteFileAtomic(path string, data []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer func() { _ = os.Remove(name) }()
	if err := tmp.Chmod(perm); err != nil && runtime.GOOS != "windows" {
		_ = tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(name, path)
}
