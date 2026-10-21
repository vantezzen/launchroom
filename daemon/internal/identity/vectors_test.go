package identity

import (
	"bytes"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"flag"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// Run `go test ./internal/identity -run TestSigningVectors -update` to regenerate
// protocol/fixtures/signing/*.json after an intentional change to the profile.
var update = flag.Bool("update", false, "rewrite protocol/fixtures/signing test vectors")

const vectorDir = "../../../protocol/fixtures/signing"

// Fixed, publicly known test keys. NEVER use them for anything else.
var (
	daemonSeed = seq(0x00) // 00 01 02 … 1f
	cpSeed     = seq(0x20) // 20 21 22 … 3f
	nonceRaw   = []byte("launchroom-nonce")
	vecTime    = time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)
)

const vecServerID = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"

func seq(start byte) []byte {
	b := make([]byte, 32)
	for i := range b {
		b[i] = start + byte(i)
	}
	return b
}

type requestVector struct {
	Description   string        `json:"description"`
	PrivateSeed   string        `json:"privateKeySeed"`
	PublicKey     string        `json:"publicKey"`
	KeyID         string        `json:"keyId"`
	Created       int64         `json:"created"`
	NonceBytes    string        `json:"nonceBytes"`
	Request       vectorRequest `json:"request"`
	Expected      vectorSigned  `json:"expected"`
	VerifyAtUnix  int64         `json:"verifyAt"`
	MaxSkewSecond int           `json:"maxSkewSeconds"`
}

type vectorRequest struct {
	Method string `json:"method"`
	URL    string `json:"url"`
	Body   string `json:"body"`
}

type vectorSigned struct {
	ContentDigest  string `json:"contentDigest"`
	Date           string `json:"date"`
	Nonce          string `json:"nonce"`
	SignatureInput string `json:"signatureInput"`
	Signature      string `json:"signature"`
	SignatureBase  string `json:"signatureBase"`
}

type responseVector struct {
	Description string            `json:"description"`
	PrivateSeed string            `json:"privateKeySeed"`
	PublicKey   string            `json:"publicKey"`
	KID         string            `json:"kid"`
	Cases       []responseVecCase `json:"cases"`
}

type responseVecCase struct {
	Name   string `json:"name"`
	Body   string `json:"body"`
	Header string `json:"header"`
	Valid  bool   `json:"valid"`
}

func vectorSigner(t *testing.T, keyID string) *Signer {
	t.Helper()
	s, err := NewSigner(keyID, ed25519.NewKeyFromSeed(daemonSeed))
	if err != nil {
		t.Fatal(err)
	}
	s.now = func() time.Time { return vecTime }
	s.rand = bytes.NewReader(nonceRaw)
	return s
}

func buildRequestVector(t *testing.T, desc, keyID, method, rawURL, body string) requestVector {
	t.Helper()
	u, err := url.Parse(rawURL)
	if err != nil {
		t.Fatal(err)
	}
	sh, err := vectorSigner(t, keyID).Sign(method, u, []byte(body))
	if err != nil {
		t.Fatal(err)
	}
	pub, _ := ed25519.NewKeyFromSeed(daemonSeed).Public().(ed25519.PublicKey)
	return requestVector{
		Description:   desc,
		PrivateSeed:   base64.StdEncoding.EncodeToString(daemonSeed),
		PublicKey:     FormatPublicKey(pub),
		KeyID:         keyID,
		Created:       vecTime.Unix(),
		NonceBytes:    base64.StdEncoding.EncodeToString(nonceRaw),
		Request:       vectorRequest{Method: method, URL: rawURL, Body: body},
		Expected:      vectorSigned(sh),
		VerifyAtUnix:  vecTime.Unix(),
		MaxSkewSecond: 60,
	}
}

func buildResponseVector(t *testing.T) responseVector {
	t.Helper()
	key := ed25519.NewKeyFromSeed(cpSeed)
	pub, _ := key.Public().(ed25519.PublicKey)
	body := `{"apiVersion":"launchroom.vantezzen.io/v1","kind":"Example","value":1}`
	good, err := SignResponse("cp-2026-10", key, []byte(body))
	if err != nil {
		t.Fatal(err)
	}
	otherKey := ed25519.NewKeyFromSeed(daemonSeed)
	wrongKey, err := SignResponse("cp-2026-10", otherKey, []byte(body))
	if err != nil {
		t.Fatal(err)
	}
	return responseVector{
		Description: "X-LR-Signature over the exact response body bytes. Trusted key set: {kid: publicKey}.",
		PrivateSeed: base64.StdEncoding.EncodeToString(cpSeed),
		PublicKey:   FormatPublicKey(pub),
		KID:         "cp-2026-10",
		Cases: []responseVecCase{
			{Name: "valid", Body: body, Header: good, Valid: true},
			{Name: "body-with-trailing-newline", Body: body + "\n", Header: good, Valid: false},
			{Name: "body-reformatted", Body: strings.ReplaceAll(body, ",", ", "), Header: good, Valid: false},
			{Name: "unknown-kid", Body: body, Header: strings.Replace(good, "kid=cp-2026-10", "kid=cp-other", 1), Valid: false},
			{Name: "wrong-key", Body: body, Header: wrongKey, Valid: false},
			{Name: "no-space-after-comma", Body: body, Header: strings.Replace(good, ", sig=", ",sig=", 1), Valid: false},
			{Name: "empty-header", Body: body, Header: "", Valid: false},
		},
	}
}

func TestSigningVectors(t *testing.T) {
	requests := map[string]requestVector{
		"request-post-lease.json": buildRequestVector(t,
			"POST with JSON body, signed by an enrolled daemon.",
			vecServerID, http.MethodPost, "https://cp.example.test/daemon/v1/tasks/lease", `{"apiVersion":"launchroom.vantezzen.io/v1","kind":"LeaseRequest","max":1}`),
		"request-get-state.json": buildRequestVector(t,
			"GET with a query string and empty body (Content-Digest is the digest of zero bytes).",
			vecServerID, http.MethodGet, "https://cp.example.test/daemon/v1/state?have=1842", ""),
		"request-enroll.json": buildRequestVector(t,
			"Enrollment: keyid is the literal \"enroll\"; the dashboard verifies with the signingKey from the body.",
			EnrollKeyID, http.MethodPost, "https://cp.example.test/daemon/v1/enroll", `{"token":"example"}`),
	}
	files := map[string]any{"response.json": buildResponseVector(t)}
	for name, v := range requests {
		files[name] = v
	}

	for name, v := range files {
		got, err := json.Marshal(v, jsontext.WithIndent("  "), json.Deterministic(true))
		if err != nil {
			t.Fatal(err)
		}
		got = append(got, '\n')
		path := filepath.Join(vectorDir, name)
		if *update {
			if err := os.MkdirAll(vectorDir, 0o750); err != nil {
				t.Fatal(err)
			}
			if err := os.WriteFile(path, got, 0o644); err != nil { //nolint:gosec // fixtures are public
				t.Fatal(err)
			}
			continue
		}
		want, err := os.ReadFile(path) //nolint:gosec // test fixture path
		if err != nil {
			t.Fatalf("%v (run with -update to generate)", err)
		}
		if !bytes.Equal(got, want) {
			t.Errorf("%s is stale; run `go test ./internal/identity -run TestSigningVectors -update`", name)
		}
	}
}

// TestRequestVectorsVerify consumes the committed fixtures the same way the
// PHP side should: rebuild the request, verify it, and check tampering fails.
func TestRequestVectorsVerify(t *testing.T) {
	for _, name := range []string{"request-post-lease.json", "request-get-state.json", "request-enroll.json"} {
		t.Run(name, func(t *testing.T) {
			raw, err := os.ReadFile(filepath.Join(vectorDir, name)) //nolint:gosec // test fixture path
			if err != nil {
				t.Fatal(err)
			}
			var v requestVector
			if err := json.Unmarshal(raw, &v, json.RejectUnknownMembers(true)); err != nil {
				t.Fatal(err)
			}
			pub, err := ParsePublicKey(v.PublicKey)
			if err != nil {
				t.Fatal(err)
			}
			mkReq := func(body string) *http.Request {
				r := httptest.NewRequestWithContext(t.Context(), v.Request.Method, v.Request.URL, strings.NewReader(body))
				r.Header.Set("Content-Digest", v.Expected.ContentDigest)
				r.Header.Set("Date", v.Expected.Date)
				r.Header.Set("X-LR-Nonce", v.Expected.Nonce)
				r.Header.Set("Signature-Input", v.Expected.SignatureInput)
				r.Header.Set("Signature", v.Expected.Signature)
				return r
			}
			opts := RequestVerifyOptions{
				LookupKey: func(kid string) (ed25519.PublicKey, error) {
					if kid != v.KeyID {
						t.Fatalf("lookup for unexpected kid %q", kid)
					}
					return pub, nil
				},
				Now: time.Unix(v.VerifyAtUnix, 0),
			}
			got, err := VerifyRequest(mkReq(v.Request.Body), []byte(v.Request.Body), opts)
			if err != nil {
				t.Fatalf("valid vector rejected: %v", err)
			}
			if got.KeyID != v.KeyID {
				t.Fatalf("keyid = %q", got.KeyID)
			}

			tampered := v.Request.Body + " "
			if _, err := VerifyRequest(mkReq(tampered), []byte(tampered), opts); err == nil {
				t.Fatal("tampered body accepted")
			}
			late := opts
			late.Now = time.Unix(v.VerifyAtUnix+61, 0)
			if _, err := VerifyRequest(mkReq(v.Request.Body), []byte(v.Request.Body), late); err == nil {
				t.Fatal("expired date accepted")
			}
			r := mkReq(v.Request.Body)
			r.Method = "DELETE"
			if _, err := VerifyRequest(r, []byte(v.Request.Body), opts); err == nil {
				t.Fatal("changed method accepted")
			}
			r = mkReq(v.Request.Body)
			r.URL.RawQuery = "have=0"
			if _, err := VerifyRequest(r, []byte(v.Request.Body), opts); err == nil {
				t.Fatal("changed query accepted")
			}
			r = mkReq(v.Request.Body)
			r.Header.Set("X-LR-Nonce", "AAAAAAAAAAAAAAAAAAAAAA")
			if _, err := VerifyRequest(r, []byte(v.Request.Body), opts); err == nil {
				t.Fatal("changed nonce accepted")
			}
		})
	}
}

func TestResponseVectorsVerify(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join(vectorDir, "response.json")) //nolint:gosec // test fixture path
	if err != nil {
		t.Fatal(err)
	}
	var v responseVector
	if err := json.Unmarshal(raw, &v, json.RejectUnknownMembers(true)); err != nil {
		t.Fatal(err)
	}
	ks, err := ParseKeySet(map[string]string{v.KID: v.PublicKey})
	if err != nil {
		t.Fatal(err)
	}
	for _, c := range v.Cases {
		_, err := ks.Verify(c.Header, []byte(c.Body))
		if (err == nil) != c.Valid {
			t.Errorf("%s: valid=%v, err=%v", c.Name, c.Valid, err)
		}
	}
}
