package identity

import (
	"crypto/ed25519"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestLoadOrCreatePersistsAndReloads(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "keys")

	id1, created, err := LoadOrCreate(dir)
	if err != nil {
		t.Fatal(err)
	}
	if !created {
		t.Fatal("expected keys to be created")
	}

	for name, want := range map[string]os.FileMode{"": 0o700, signingKeyFile: 0o600, sealingKeyFile: 0o600} {
		fi, err := os.Stat(filepath.Join(dir, name))
		if err != nil {
			t.Fatal(err)
		}
		if fi.Mode().Perm() != want {
			t.Errorf("%q perm = %v, want %v", name, fi.Mode().Perm(), want)
		}
	}

	id2, created, err := LoadOrCreate(dir)
	if err != nil {
		t.Fatal(err)
	}
	if created {
		t.Fatal("keys regenerated on second start")
	}
	if !id1.SigningPublicKey().Equal(id2.SigningPublicKey()) {
		t.Fatal("signing key changed across restarts")
	}
	if !id1.SealingPublicKey().Equal(id2.SealingPublicKey()) {
		t.Fatal("sealing key changed across restarts")
	}
}

func TestLoadOrCreateRejectsUnsafeState(t *testing.T) {
	t.Run("loose file permissions", func(t *testing.T) {
		dir := filepath.Join(t.TempDir(), "keys")
		if _, _, err := LoadOrCreate(dir); err != nil {
			t.Fatal(err)
		}
		if err := os.Chmod(filepath.Join(dir, signingKeyFile), 0o644); err != nil { //nolint:gosec // deliberately unsafe for the test
			t.Fatal(err)
		}
		if _, _, err := LoadOrCreate(dir); err == nil || !strings.Contains(err.Error(), "permissions") {
			t.Fatalf("err = %v", err)
		}
	})
	t.Run("loose dir permissions", func(t *testing.T) {
		dir := filepath.Join(t.TempDir(), "keys")
		if err := os.MkdirAll(dir, 0o755); err != nil { //nolint:gosec // deliberately unsafe for the test
			t.Fatal(err)
		}
		if err := os.Chmod(dir, 0o755); err != nil { //nolint:gosec // deliberately unsafe for the test
			t.Fatal(err)
		}
		if _, _, err := LoadOrCreate(dir); err == nil {
			t.Fatal("expected error")
		}
	})
	t.Run("one key missing", func(t *testing.T) {
		dir := filepath.Join(t.TempDir(), "keys")
		if _, _, err := LoadOrCreate(dir); err != nil {
			t.Fatal(err)
		}
		if err := os.Remove(filepath.Join(dir, sealingKeyFile)); err != nil {
			t.Fatal(err)
		}
		if _, _, err := LoadOrCreate(dir); err == nil || !strings.Contains(err.Error(), "refusing") {
			t.Fatalf("err = %v", err)
		}
	})
	t.Run("garbage key", func(t *testing.T) {
		dir := filepath.Join(t.TempDir(), "keys")
		if _, _, err := LoadOrCreate(dir); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(dir, signingKeyFile), []byte("nope"), 0o600); err != nil {
			t.Fatal(err)
		}
		if _, _, err := LoadOrCreate(dir); err == nil {
			t.Fatal("expected error")
		}
	})
}

func TestPublicKeyFormats(t *testing.T) {
	id, err := Generate()
	if err != nil {
		t.Fatal(err)
	}
	s := FormatPublicKey(id.SigningPublicKey())
	pub, err := ParsePublicKey(s)
	if err != nil {
		t.Fatal(err)
	}
	if !pub.Equal(id.SigningPublicKey()) {
		t.Fatal("round trip mismatch")
	}
	x := FormatSealingKey(id.SealingPublicKey())
	xp, err := ParseSealingKey(x)
	if err != nil {
		t.Fatal(err)
	}
	if !xp.Equal(id.SealingPublicKey()) {
		t.Fatal("sealing round trip mismatch")
	}

	for _, bad := range []string{"", "ed25519:", "ed25519:AAAA", "rsa:" + strings.TrimPrefix(s, Ed25519Prefix), s + "=", strings.Replace(s, "ed25519:", "ed25519: ", 1)} {
		if _, err := ParsePublicKey(bad); err == nil {
			t.Errorf("ParsePublicKey(%q) accepted", bad)
		}
	}

	fp := Fingerprint(id.SigningPublicKey())
	if !strings.HasPrefix(fp, "SHA256:") || len(fp) != len("SHA256:")+43 {
		t.Fatalf("fingerprint = %q", fp)
	}
}

func TestTransportSignsRequests(t *testing.T) {
	id, err := Generate()
	if err != nil {
		t.Fatal(err)
	}
	signer, err := id.NewSigner(vecServerID)
	if err != nil {
		t.Fatal(err)
	}

	var verifyErr error
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		_, verifyErr = VerifyRequest(r, body, RequestVerifyOptions{
			LookupKey: func(kid string) (ed25519.PublicKey, error) {
				if kid != vecServerID {
					return nil, errors.New("unknown")
				}
				return id.SigningPublicKey(), nil
			},
		})
		w.WriteHeader(http.StatusNoContent)
	}))
	defer srv.Close()

	client := &http.Client{Transport: &Transport{Base: srv.Client().Transport, Signer: signer}, Timeout: 5 * time.Second}
	for _, tc := range []struct{ method, path, body string }{
		{http.MethodGet, "/daemon/v1/state?have=3", ""},
		{http.MethodPost, "/daemon/v1/reports", `{"a":1}`},
		{http.MethodPost, "/daemon/v1/tasks/tsk_01J9Z8X7W6V5T4S3R2Q1P0N9MK/complete", "x"},
	} {
		req, err := http.NewRequestWithContext(t.Context(), tc.method, srv.URL+tc.path, strings.NewReader(tc.body))
		if err != nil {
			t.Fatal(err)
		}
		resp, err := client.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		_ = resp.Body.Close()
		if verifyErr != nil {
			t.Fatalf("%s %s: %v", tc.method, tc.path, verifyErr)
		}
		if req.Header.Get("Signature") != "" {
			t.Fatal("transport mutated the caller's request")
		}
	}
}

func TestNewSignerRejectsBadKeyID(t *testing.T) {
	key := ed25519.NewKeyFromSeed(daemonSeed)
	for _, kid := range []string{"", "srv_lower", "srv_01J9Z8X7W6V5T4S3R2Q1P0N9M", `x"y`, "enroll "} {
		if _, err := NewSigner(kid, key); err == nil {
			t.Errorf("NewSigner(%q) accepted", kid)
		}
	}
}

func TestVerifyFailsClosed(t *testing.T) {
	key := ed25519.NewKeyFromSeed(cpSeed)
	pub, _ := key.Public().(ed25519.PublicKey)
	body := []byte(`{"x":1}`)
	h, err := SignResponse("k1", key, body)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := (KeySet{}).Verify(h, body); err == nil {
		t.Fatal("empty key set accepted")
	}
	if _, err := (KeySet(nil)).Verify(h, body); err == nil {
		t.Fatal("nil key set accepted")
	}
	ks := KeySet{"k1": pub}
	if kid, err := ks.Verify(h, body); err != nil || kid != "k1" {
		t.Fatalf("valid rejected: %v", err)
	}
	if _, err := ks.Verify(h, append(body, ' ')); !errors.Is(err, ErrBadResponseSignature) {
		t.Fatalf("tampered body: %v", err)
	}
	if _, err := VerifyWithKey(nil, h, body); err == nil {
		t.Fatal("nil pinned key accepted")
	}
	if kid, err := VerifyWithKey(pub, h, body); err != nil || kid != "k1" {
		t.Fatalf("VerifyWithKey: %v", err)
	}
	if _, err := ParseKeySet(map[string]string{"bad kid": FormatPublicKey(pub)}); err == nil {
		t.Fatal("bad kid accepted")
	}
}
