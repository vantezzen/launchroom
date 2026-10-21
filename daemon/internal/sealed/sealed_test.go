package sealed

import (
	"bytes"
	"crypto/ecdh"
	"crypto/rand"
	"encoding/base64"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"flag"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Run `go test ./internal/sealed -run TestSealingVectors -update` (or `make
// gen-protocol`) to regenerate protocol/fixtures/sealing/workload-env.json.
var update = flag.Bool("update", false, "rewrite protocol/fixtures/sealing test vectors")

const (
	vectorFile   = "../../../protocol/fixtures/sealing/workload-env.json"
	vecServerID  = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	vecWorkload  = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	vecOtherWl   = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8")
	vecOtherSrv  = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MZ")
	vecPlainText = `{"serverId":"srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK","workloadId":"wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7","env":{"DATABASE_URL":"postgres://app:s3cret@postgres:5432/app","EMPTY":"","GREETING":"hällo \"world\"\n"}}`
)

// Fixed, publicly known test keys. NEVER use them for anything else.
func seq(start byte) []byte {
	b := make([]byte, 32)
	for i := range b {
		b[i] = start + byte(i)
	}
	return b
}

func vecKey(t testing.TB) *ecdh.PrivateKey {
	t.Helper()
	k, err := ecdh.X25519().NewPrivateKey(seq(0x40))
	if err != nil {
		t.Fatal(err)
	}
	return k
}

type sealingVectors struct {
	Description         string            `json:"description"`
	RecipientPrivateKey string            `json:"recipientPrivateKey"`
	RecipientPublicKey  string            `json:"recipientPublicKey"`
	ServerID            state.ServerID    `json:"serverId"`
	WorkloadID          state.WorkloadID  `json:"workloadId"`
	Plaintext           string            `json:"plaintext"`
	Env                 map[string]string `json:"env"`
	Cases               []sealingCase     `json:"cases"`
}

type sealingCase struct {
	Description string `json:"description"`
	// EphemeralSeed is the 32 random bytes crypto_box_seal used (to make the vector deterministic).
	EphemeralSeed string `json:"ephemeralSeed,omitempty"`
	Sealed        string `json:"sealed"`
	Valid         bool   `json:"valid"`
}

func generateVectors(t *testing.T) sealingVectors {
	t.Helper()
	k := vecKey(t)
	v := sealingVectors{
		Description: "crypto_box_seal of a WorkloadEnv JSON document to the daemon X25519 key. " +
			"Open with sodium_crypto_box_seal_open(base64_decode(sealed), sodium_crypto_box_keypair_from_secretkey_and_publickey(sk, pk)). " +
			"Only the case with valid=true opens to plaintext AND binds serverId/workloadId. Public test key: never use elsewhere.",
		RecipientPrivateKey: base64.StdEncoding.EncodeToString(k.Bytes()),
		RecipientPublicKey:  "x25519:" + base64.StdEncoding.EncodeToString(k.PublicKey().Bytes()),
		ServerID:            vecServerID,
		WorkloadID:          vecWorkload,
		Plaintext:           vecPlainText,
		Env: map[string]string{
			"DATABASE_URL": "postgres://app:s3cret@postgres:5432/app",
			"EMPTY":        "",
			"GREETING":     "hällo \"world\"\n",
		},
	}
	seal := func(desc string, seed byte, plain string, valid bool) {
		eph := seq(seed)
		b, err := Seal([]byte(plain), k.PublicKey(), bytes.NewReader(eph))
		if err != nil {
			t.Fatal(err)
		}
		v.Cases = append(v.Cases, sealingCase{Description: desc, EphemeralSeed: base64.StdEncoding.EncodeToString(eph), Sealed: string(b), Valid: valid})
	}
	seal("valid: opens to plaintext, bound to serverId/workloadId", 0x60, vecPlainText, true)
	seal("invalid: sealed for another workload (re-targeting)", 0x61, strings.Replace(vecPlainText, string(vecWorkload), string(vecOtherWl), 1), false)
	seal("invalid: sealed for another server", 0x62, strings.Replace(vecPlainText, string(vecServerID), string(vecOtherSrv), 1), false)
	seal("invalid: unknown member", 0x63, strings.Replace(vecPlainText, `"env":`, `"extra":1,"env":`, 1), false)
	seal("invalid: bad variable name", 0x64, strings.Replace(vecPlainText, `"EMPTY"`, `"1EMPTY"`, 1), false)

	// Tampered: flip one ciphertext byte of the valid case.
	raw, _ := base64.StdEncoding.DecodeString(v.Cases[0].Sealed)
	raw[len(raw)-1] ^= 1
	v.Cases = append(v.Cases, sealingCase{Description: "invalid: tampered ciphertext", Sealed: base64.StdEncoding.EncodeToString(raw), Valid: false})
	return v
}

func TestSealingVectors(t *testing.T) {
	want := generateVectors(t)
	data, err := json.Marshal(want, json.Deterministic(true))
	if err != nil {
		t.Fatal(err)
	}
	if err := (*jsontext.Value)(&data).Indent(jsontext.WithIndent("  ")); err != nil {
		t.Fatal(err)
	}
	data = append(data, '\n')
	if *update {
		if err := os.MkdirAll(filepath.Dir(vectorFile), 0o750); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(vectorFile, data, 0o644); err != nil { //nolint:gosec // public test vectors
			t.Fatal(err)
		}
	}
	got, err := os.ReadFile(vectorFile)
	if err != nil {
		t.Fatalf("%v (run with -update to generate)", err)
	}
	if !bytes.Equal(got, data) {
		t.Fatalf("%s is stale; run `make gen-protocol`", vectorFile)
	}

	// Verify every committed case behaves as declared.
	var vec sealingVectors
	if err := json.Unmarshal(got, &vec); err != nil {
		t.Fatal(err)
	}
	k := vecKey(t)
	for _, c := range vec.Cases {
		t.Run(c.Description, func(t *testing.T) {
			env, err := OpenWorkloadEnv(state.Sealed{Sealed: state.SealedBox(c.Sealed)}, k, vec.ServerID, vec.WorkloadID)
			if c.Valid != (err == nil) {
				t.Fatalf("valid=%v, err=%v", c.Valid, err)
			}
			if !c.Valid {
				return
			}
			if len(env) != len(vec.Env) {
				t.Fatalf("got %d vars", len(env))
			}
			for name, val := range vec.Env {
				if env[name].Reveal() != val {
					t.Errorf("%s mismatch", name)
				}
			}
			plain, err := Open(state.SealedBox(c.Sealed), k)
			if err != nil || string(plain) != vec.Plaintext {
				t.Errorf("plaintext mismatch: %v", err)
			}
		})
	}
}

func TestRoundTripRandom(t *testing.T) {
	k, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	in := state.WorkloadEnv{ServerID: vecServerID, WorkloadID: vecWorkload, Env: map[string]secret.Secret{"A": "1", "B": "two"}}
	s, err := SealWorkloadEnv(in, k.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	if err := s.Validate(); err != nil {
		t.Fatalf("sealed value does not pass protocol validation: %v", err)
	}
	env, err := OpenWorkloadEnv(s, k, vecServerID, vecWorkload)
	if err != nil {
		t.Fatal(err)
	}
	if env["B"].Reveal() != "two" || len(env) != 2 {
		t.Fatalf("env = %v", env)
	}
	// Another key cannot open it.
	other, _ := ecdh.X25519().GenerateKey(rand.Reader)
	if _, err := OpenWorkloadEnv(s, other, vecServerID, vecWorkload); !errors.Is(err, ErrOpen) {
		t.Fatalf("err = %v", err)
	}
	// Re-targeting is rejected.
	if _, err := OpenWorkloadEnv(s, k, vecServerID, vecOtherWl); err == nil {
		t.Fatal("re-targeted env accepted")
	}
}

func TestErrorsDoNotLeakValues(t *testing.T) {
	k := vecKey(t)
	plain := `{"serverId":"srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK","workloadId":"wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7","env":{"K":"topsecret` + "\x00" + `"},"x":"topsecret"}`
	b, err := Seal([]byte(plain), k.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	_, err = OpenWorkloadEnv(state.Sealed{Sealed: b}, k, vecServerID, vecWorkload)
	if err == nil || strings.Contains(err.Error(), "topsecret") {
		t.Fatalf("err = %v", err)
	}
	b, _ = Seal([]byte(strings.Replace(plain, `,"x":"topsecret"`, "", 1)), k.PublicKey(), rand.Reader)
	_, err = OpenWorkloadEnv(state.Sealed{Sealed: b}, k, vecServerID, vecWorkload)
	if err == nil || strings.Contains(err.Error(), "topsecret") {
		t.Fatalf("err = %v", err)
	}
}

func TestOpenRejectsGarbage(t *testing.T) {
	k := vecKey(t)
	for _, b := range []state.SealedBox{"", "not base64!", state.SealedBox(strings.Repeat("A", 64))} {
		if _, err := Open(b, k); err == nil {
			t.Errorf("%q accepted", b)
		}
	}
	if _, err := Open(state.SealedBox(strings.Repeat("A", 64)), nil); err == nil {
		t.Error("nil key accepted")
	}
}

func FuzzOpenWorkloadEnvPlaintext(f *testing.F) {
	f.Add([]byte(vecPlainText))
	f.Add([]byte(`{}`))
	f.Add([]byte(`{"serverId":"srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK","workloadId":"wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7","env":null}`))
	k := vecKey(f)
	f.Fuzz(func(t *testing.T, plain []byte) {
		b, err := Seal(plain, k.PublicKey(), bytes.NewReader(seq(0x70)))
		if err != nil {
			t.Fatal(err)
		}
		env, err := OpenWorkloadEnv(state.Sealed{Sealed: b}, k, vecServerID, vecWorkload)
		if err != nil {
			return
		}
		for name := range env {
			if !reName(name) {
				t.Fatalf("accepted invalid name %q", name)
			}
		}
	})
}

func reName(s string) bool {
	return (&state.WorkloadEnv{ServerID: vecServerID, WorkloadID: vecWorkload, Env: map[string]secret.Secret{s: ""}}).Validate() == nil
}

func TestOpenTaskSecrets(t *testing.T) {
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	const srv, task = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"), state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0")
	tok, err := SealJSON(state.TaskToken{ServerID: srv, TaskID: task, Purpose: state.PurposeClone, Token: "ghs_secret"}, priv.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	got, err := OpenTaskToken(tok, priv, srv, task, state.PurposeClone)
	if err != nil || got.Reveal() != "ghs_secret" {
		t.Fatalf("open token: %v", err)
	}
	if _, err := OpenTaskToken(tok, priv, srv, "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P1", state.PurposeClone); err == nil {
		t.Fatal("re-targeted token accepted")
	}
	env, err := SealJSON(state.TaskEnv{ServerID: srv, TaskID: task, Env: map[string]secret.Secret{"NPM_TOKEN": "npm_x"}}, priv.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	vals, err := OpenTaskEnv(env, priv, srv, task)
	if err != nil || vals["NPM_TOKEN"].Reveal() != "npm_x" {
		t.Fatalf("open env: %v", err)
	}
	if _, err := OpenTaskEnv(env, priv, "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MM", task); err == nil {
		t.Fatal("env for another server accepted")
	}
	if _, err := OpenTaskEnv(tok, priv, srv, task); err == nil {
		t.Fatal("token accepted as env")
	}
}
