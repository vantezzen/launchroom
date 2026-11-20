package format

import (
	"crypto/aes"
	"crypto/cipher"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"strings"
	"testing"
)

// fixtureDir is protocol/fixtures/v1/trace (docs/23 §2.4). With
// LR_TRACE_FIXTURES_UPDATE=1 the tests write every "header" from its recipe.
var fixtureDir = filepath.Join("..", "..", "..", "..", "..", "protocol", "fixtures", "v1", "trace")

var update = os.Getenv("LR_TRACE_FIXTURES_UPDATE") == "1"

type keyFixture struct {
	KeyID uint32 `json:"keyId"`
	Key   string `json:"key"`
	Edge  string `json:"edge"`
}

type recipe struct {
	KeyID   uint32          `json:"keyId"`
	Version *byte           `json:"version"`
	Prefix  *string         `json:"prefix"`
	Nonce   string          `json:"nonce"`
	Payload string          `json:"payload"`
	Mutate  *mutation       `json:"mutate"`
	Fields  json.RawMessage `json:"fields"`
	Reason  string          `json:"reason"`
	Header  *string         `json:"header"`
}

type mutation struct {
	Truncate *int    `json:"truncate"`
	FlipByte *int    `json:"flipByte"`
	AADKeyID *uint32 `json:"aadKeyId"`
	Append   string  `json:"append"`
}

func testKey(t *testing.T) (keyFixture, cipher.AEAD) {
	t.Helper()
	var k keyFixture
	readJSON(t, filepath.Join(fixtureDir, "key.json"), &k)
	raw, err := base64.StdEncoding.DecodeString(k.Key)
	if err != nil || len(raw) != 32 {
		t.Fatalf("key.json: bad key")
	}
	b, err := aes.NewCipher(raw)
	if err != nil {
		t.Fatal(err)
	}
	g, err := cipher.NewGCM(b)
	if err != nil {
		t.Fatal(err)
	}
	return k, g
}

func readJSON(t *testing.T, path string, v any) []byte {
	t.Helper()
	data, err := os.ReadFile(path) //nolint:gosec // test fixture
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(data, v); err != nil {
		t.Fatalf("%s: %v", path, err)
	}
	return data
}

// prefix is the recipe's text prefix: Prefix unless the recipe names one
// ("" for a value from an edge before the prefix).
func (r recipe) prefix() string {
	if r.Prefix != nil {
		return *r.Prefix
	}
	return Prefix
}

// build makes the header a recipe describes (fixtures README).
func build(t *testing.T, aead cipher.AEAD, r recipe) string {
	t.Helper()
	payload, err := hex.DecodeString(r.Payload)
	if err != nil {
		t.Fatalf("payload: %v", err)
	}
	nonce, err := hex.DecodeString(r.Nonce)
	if err != nil || len(nonce) != NonceSize {
		t.Fatalf("nonce %q", r.Nonce)
	}
	version := byte(Version)
	if r.Version != nil {
		version = *r.Version
	}
	aad := []byte{version, 0, 0, 0, 0}
	binary.BigEndian.PutUint32(aad[1:], r.KeyID)
	sealAAD := append([]byte(nil), aad...)
	if r.Mutate != nil && r.Mutate.AADKeyID != nil {
		binary.BigEndian.PutUint32(sealAAD[1:], *r.Mutate.AADKeyID)
	}
	raw := aead.Seal(append(append([]byte(nil), aad...), nonce...), nonce, payload, sealAAD)
	suffix := ""
	if m := r.Mutate; m != nil {
		if m.Truncate != nil {
			raw = raw[:*m.Truncate]
		}
		if m.FlipByte != nil {
			i := *m.FlipByte
			if i < 0 {
				i += len(raw)
			}
			raw[i] ^= 0x01
		}
		suffix = m.Append
	}
	return r.prefix() + base64.RawURLEncoding.EncodeToString(raw) + suffix
}

var reHeader = regexp.MustCompile(`"header": (null|"[^"]*")`)

// checkHeader compares the fixture's header with want, or writes it.
func checkHeader(t *testing.T, path string, data []byte, got *string, want string) {
	t.Helper()
	if got != nil && *got == want {
		return
	}
	if !update {
		t.Fatalf("header is stale or missing (run with LR_TRACE_FIXTURES_UPDATE=1): want %s", want)
	}
	q, _ := json.Marshal(want)
	out := reHeader.ReplaceAll(data, []byte(`"header": `+string(q)))
	if err := os.WriteFile(path, out, 0o644); err != nil { //nolint:gosec // fixture file
		t.Fatal(err)
	}
}

func keys(k keyFixture, aead cipher.AEAD) func(uint32) cipher.AEAD {
	return func(id uint32) cipher.AEAD {
		if id == k.KeyID {
			return aead
		}
		return nil
	}
}

func TestValidFixtures(t *testing.T) {
	k, aead := testKey(t)
	files, _ := filepath.Glob(filepath.Join(fixtureDir, "valid", "*.json"))
	if len(files) == 0 {
		t.Fatal("no valid fixtures")
	}
	for _, path := range files {
		t.Run(filepath.Base(path), func(t *testing.T) {
			var r recipe
			data := readJSON(t, path, &r)
			var want Fields
			dec := json.NewDecoder(strings.NewReader(string(r.Fields)))
			dec.DisallowUnknownFields()
			if err := dec.Decode(&want); err != nil {
				t.Fatalf("fields: %v", err)
			}
			if want.Firewall != nil && want.Firewall.Rules == nil {
				want.Firewall.Rules = []uint32{}
			}
			payload, _ := hex.DecodeString(r.Payload)
			got, err := Decode(payload)
			if err != nil || !reflect.DeepEqual(got, want) {
				gj, _ := json.Marshal(got)
				t.Fatalf("Decode = %s, %v; want %s", gj, err, r.Fields)
			}
			if !strings.Contains(filepath.Base(path), "unknown-tag") {
				enc, err := Encode(want)
				if err != nil || hex.EncodeToString(enc) != r.Payload {
					t.Fatalf("Encode = %x, %v; want %s", enc, err, r.Payload)
				}
			}
			header := build(t, aead, r)
			nonce, _ := hex.DecodeString(r.Nonce)
			sealed, err := Seal(aead, r.KeyID, nonce, payload)
			if err != nil || sealed != Prefix+strings.TrimPrefix(header, r.prefix()) {
				t.Fatalf("Seal = %s, %v; want %s", sealed, err, header)
			}
			id, opened, err := Open(header, keys(k, aead))
			if err != nil || id != k.KeyID || hex.EncodeToString(opened) != r.Payload {
				t.Fatalf("Open = %d, %x, %v", id, opened, err)
			}
			checkHeader(t, path, data, r.Header, header)
		})
	}
}

func TestInvalidFixtures(t *testing.T) {
	k, aead := testKey(t)
	files, _ := filepath.Glob(filepath.Join(fixtureDir, "invalid", "*.json"))
	if len(files) == 0 {
		t.Fatal("no invalid fixtures")
	}
	for _, path := range files {
		t.Run(filepath.Base(path), func(t *testing.T) {
			var r recipe
			data := readJSON(t, path, &r)
			switch r.Reason {
			case "format", "newer", "key", "auth", "payload":
			default:
				t.Fatalf("reason %q", r.Reason)
			}
			header := ""
			if r.Payload != "" {
				header = build(t, aead, r)
				checkHeader(t, path, data, r.Header, header)
			} else if r.Header != nil {
				header = *r.Header
			}
			_, p, err := Open(header, keys(k, aead))
			if r.Reason == "payload" {
				if err != nil {
					t.Fatalf("Open: %v (the payload should be what fails)", err)
				}
				_, err = Decode(p)
			}
			if !errors.Is(err, ErrUnreadable) {
				t.Fatalf("err = %v, want ErrUnreadable", err)
			}
		})
	}
}

func TestEncodeLimits(t *testing.T) {
	base := Fields{
		ReceivedAt: 1, RequestID: "3f1c9a2e-8b7d-4e6f-a5b4-c3d2e1f00a1b",
		Edge: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Status: 200, Origin: OriginApp,
	}
	f := base
	f.Path = "/" + strings.Repeat("a", 126) + "é" // 129 bytes, é straddles 128
	f.Method = "AVERYLONGMETHODNAME"
	f.Firewall = &Firewall{Mode: FirewallModeBlock, Outcome: FirewallBlocked, Rules: []uint32{1, 2, 3, 4, 5, 6, 7, 8, 9}}
	p, err := Encode(f)
	if err != nil {
		t.Fatal(err)
	}
	got, err := Decode(p)
	if err != nil {
		t.Fatal(err)
	}
	if len(got.Path) != 127 || got.Method != "" || len(got.Firewall.Rules) != MaxRules {
		t.Fatalf("got path %d bytes, method %q, %d rules", len(got.Path), got.Method, len(got.Firewall.Rules))
	}
	for _, bad := range []func(*Fields){
		func(f *Fields) { f.Edge = "srv_bad" },
		func(f *Fields) { f.Route = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK" },
		func(f *Fields) { f.RequestID = "nope" },
		func(f *Fields) { f.Origin = 9 },
	} {
		f := base
		bad(&f)
		if _, err := Encode(f); err == nil {
			t.Errorf("Encode(%+v) succeeded", f)
		}
	}
}

func TestIDs(t *testing.T) {
	for _, id := range []string{"srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", "srv_00000000000000000000000000", "srv_7ZZZZZZZZZZZZZZZZZZZZZZZZZ"} {
		b, err := ParseID("srv", id)
		if err != nil || IDString("srv", b) != id {
			t.Errorf("round trip %s: %s, %v", id, IDString("srv", b), err)
		}
	}
	if b, _ := ParseID("srv", "srv_7ZZZZZZZZZZZZZZZZZZZZZZZZZ"); b != [16]byte{0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff} {
		t.Errorf("max ulid = %x", b)
	}
	for _, id := range []string{"srv_81J9Z8X7W6V5T4S3R2Q1P0N9MK", "srv_01J9Z8X7W6V5T4S3R2Q1P0N9M", "srv_01J9Z8X7W6V5T4S3R2Q1P0N9ML", "rt_01J9Z8X7W6V5T4S3R2Q1P0N9MK", "srv_01j9z8x7w6v5t4s3r2q1p0n9mk"} {
		if _, err := ParseID("srv", id); err == nil {
			t.Errorf("ParseID(%q) succeeded", id)
		}
	}
}
