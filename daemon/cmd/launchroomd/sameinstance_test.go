package main

import (
	"crypto/ed25519"
	"net/url"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/enrollment"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestCheckSameInstance(t *testing.T) {
	enrolledKey, _, _ := ed25519.GenerateKey(nil)
	otherKey, _, _ := ed25519.GenerateKey(nil)
	rec := &enrollment.Record{
		ControlPlaneURL:  "https://dash.example.com",
		ControlPlaneKeys: map[state.KeyID]state.Ed25519Key{"cp1": state.Ed25519Key(identity.FormatPublicKey(enrolledKey))},
	}
	cfg := func(u string, key ed25519.PublicKey) *config.Config {
		parsed, _ := url.Parse(u)
		return &config.Config{ControlPlaneURL: parsed, PinnedCPKey: key}
	}
	for name, tc := range map[string]struct {
		cfg    *config.Config
		refuse bool
	}{
		"same URL and key":              {cfg("https://dash.example.com", enrolledKey), false},
		"new domain, same key":          {cfg("https://new.example.com", enrolledKey), false},
		"same URL, rotated key":         {cfg("https://dash.example.com", otherKey), false},
		"no pinned key":                 {cfg("https://other.example.org", nil), false},
		"another launchroom altogether": {cfg("https://other.example.org", otherKey), true},
	} {
		err := checkSameInstance(rec, tc.cfg)
		if tc.refuse != (err != nil) {
			t.Errorf("%s: err = %v", name, err)
		}
		if err != nil && !strings.Contains(err.Error(), "https://dash.example.com") {
			t.Errorf("%s: the error should name the enrolled launchroom: %v", name, err)
		}
	}
}
