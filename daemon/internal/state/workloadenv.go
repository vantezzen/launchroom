package state

import (
	"encoding/json/v2"
	"regexp"
	"slices"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

// Limits of a workload environment.
const (
	maxEnvVars     = 1024
	maxEnvValueLen = 128 << 10
)

var reEnvName = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,255}$`)

// EnvNamePattern is the grammar of environment variable names.
const EnvNamePattern = `^[A-Za-z_][A-Za-z0-9_]{0,255}$`

// WorkloadEnv is the plaintext inside WorkloadSpec.env.sealed (docs/03 §6):
// libsodium crypto_box_seal of the UTF-8 JSON encoding of this object,
// addressed to the daemon's X25519 key. ServerID and WorkloadID bind the
// ciphertext to one workload on one server, so a sealed env cannot be
// re-targeted to another workload or server.
type WorkloadEnv struct {
	ServerID   ServerID   `json:"serverId"`
	WorkloadID WorkloadID `json:"workloadId"`
	// Env maps variable names (^[A-Za-z_][A-Za-z0-9_]{0,255}$) to values. Values
	// must not contain NUL bytes. An empty environment is {}.
	Env map[string]secret.Secret `json:"env"`
}

// workloadEnvWire is WorkloadEnv with plain string values.
type workloadEnvWire struct {
	ServerID   ServerID          `json:"serverId"`
	WorkloadID WorkloadID        `json:"workloadId"`
	Env        map[string]string `json:"env"`
}

// MarshalJSON reveals the values deliberately: it is only used to produce the
// plaintext that is sealed immediately (tests, fixtures).
func (w WorkloadEnv) MarshalJSON() ([]byte, error) {
	env := make(map[string]string, len(w.Env))
	for k, v := range w.Env {
		env[k] = v.Reveal()
	}
	return json.Marshal(workloadEnvWire{ServerID: w.ServerID, WorkloadID: w.WorkloadID, Env: env}, json.Deterministic(true))
}

// UnmarshalJSON strictly decodes the wire form.
func (w *WorkloadEnv) UnmarshalJSON(data []byte) error {
	var wire workloadEnvWire
	if err := json.Unmarshal(data, &wire, strictOptions()); err != nil {
		return err
	}
	env := make(map[string]secret.Secret, len(wire.Env))
	for k, v := range wire.Env {
		env[k] = secret.Secret(v)
	}
	*w = WorkloadEnv{ServerID: wire.ServerID, WorkloadID: wire.WorkloadID, Env: env}
	if wire.Env == nil {
		w.Env = nil
	}
	return nil
}

// Validate checks ids, names and values. Errors never contain values.
func (w *WorkloadEnv) Validate() error {
	var e errs
	e.check("serverId", w.ServerID)
	e.check("workloadId", w.WorkloadID)
	checkSecretEnv(&e, w.Env)
	return e.err()
}

// checkSecretEnv validates a decrypted environment. Errors never contain values.
func checkSecretEnv(e *errs, env map[string]secret.Secret) {
	w := struct{ Env map[string]secret.Secret }{env}
	if w.Env == nil {
		e.addf("env", "is required ({} when empty)")
	}
	if len(w.Env) > maxEnvVars {
		e.addf("env", "has %d variables, at most %d allowed", len(w.Env), maxEnvVars)
	}
	names := make([]string, 0, len(w.Env))
	for k := range w.Env {
		names = append(names, k)
	}
	slices.Sort(names)
	for _, k := range names {
		if !reEnvName.MatchString(k) {
			e.addf("env", "invalid variable name %q", truncate(k))
			continue
		}
		v := w.Env[k].Reveal()
		if len(v) > maxEnvValueLen {
			e.addf("env."+k, "value is longer than %d bytes", maxEnvValueLen)
		}
		if strings.ContainsRune(v, 0) {
			e.addf("env."+k, "value contains a NUL byte")
		}
	}
}
