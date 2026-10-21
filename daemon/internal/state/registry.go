package state

import (
	"encoding/json/v2"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

// Registry tokens (docs/05 §6) and sealed task secrets (docs/03 §6–§7).

// Document kinds.
const (
	KindRegistryTokenRequest = "RegistryTokenRequest"
	KindRegistryToken        = "RegistryToken"
	maxRegistryScopes        = 16
	maxRegistryTokenLen      = 8192
)

// RegistryScope is one repository and the actions requested on it.
type RegistryScope struct {
	Repository RegistryRepository `json:"repository"`
	Actions    []RegistryAction   `json:"actions" jsonschema:"minItems=1,maxItems=2"`
}

func checkScopes(e *errs, path string, scopes []RegistryScope) {
	checkCount(e, path, len(scopes), 1, maxRegistryScopes)
	repos := make([]RegistryRepository, 0, len(scopes))
	for i, s := range scopes {
		p := idx(path, i)
		e.check(p+".repository", s.Repository)
		checkCount(e, p+".actions", len(s.Actions), 1, 2)
		for j, a := range s.Actions {
			e.check(idx(p+".actions", j), a)
		}
		unique(e, p+".actions", s.Actions)
		repos = append(repos, s.Repository)
	}
	unique(e, path+".repository", repos)
}

// RegistryTokenRequest asks the dashboard for a short-lived registry token:
// POST /daemon/v1/registry-token. The dashboard grants all scopes or answers
// 403 (no partial grants):
//   - pull on "mirror/…" for any approved server;
//   - pull on "apps/<service>" whose images are in the server's current NodeState;
//   - pull and push on "apps/<service>" and "cache/<service>" only with Task set
//     to a build task currently leased by this server for that service.
type RegistryTokenRequest struct {
	TypeMeta
	Scopes []RegistryScope `json:"scopes" jsonschema:"minItems=1,maxItems=16"`
	// Task is the leased build/plan task the token is for (required for push and cache scopes).
	Task TaskID `json:"task,omitzero"`
}

// Validate checks the request.
func (r *RegistryTokenRequest) Validate() error {
	var e errs
	r.check(&e, KindRegistryTokenRequest)
	checkScopes(&e, "scopes", r.Scopes)
	if r.Task != "" {
		e.check("task", r.Task)
	}
	return e.err()
}

// RegistryToken is the signed response: a Docker token-spec bearer JWT for
// the registry, valid for at most 5 minutes.
type RegistryToken struct {
	TypeMeta
	// Token is the bearer JWT. It is never logged.
	Token     secret.Secret   `json:"token" jsonschema:"type=string,maxLength=8192,pattern=^[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+$"`
	ExpiresAt time.Time       `json:"expiresAt"`
	Scopes    []RegistryScope `json:"scopes" jsonschema:"minItems=1,maxItems=16"`
}

type registryTokenWire struct {
	TypeMeta
	Token     string          `json:"token"`
	ExpiresAt time.Time       `json:"expiresAt"`
	Scopes    []RegistryScope `json:"scopes"`
}

// MarshalJSON reveals the token deliberately (tests and fixtures only).
func (t RegistryToken) MarshalJSON() ([]byte, error) {
	return json.Marshal(registryTokenWire{TypeMeta: t.TypeMeta, Token: t.Token.Reveal(), ExpiresAt: t.ExpiresAt, Scopes: t.Scopes})
}

// UnmarshalJSON strictly decodes the wire form.
func (t *RegistryToken) UnmarshalJSON(data []byte) error {
	var w registryTokenWire
	if err := json.Unmarshal(data, &w, strictOptions()); err != nil {
		return err
	}
	*t = RegistryToken{TypeMeta: w.TypeMeta, Token: secret.Secret(w.Token), ExpiresAt: w.ExpiresAt, Scopes: w.Scopes}
	return nil
}

// Validate checks the response.
func (t *RegistryToken) Validate() error {
	var e errs
	t.check(&e, KindRegistryToken)
	if tok := t.Token.Reveal(); len(tok) > maxRegistryTokenLen || !reJWT.MatchString(tok) {
		e.addf("token", "is not a JWT")
	}
	if t.ExpiresAt.IsZero() {
		e.addf("expiresAt", "is required")
	}
	checkScopes(&e, "scopes", t.Scopes)
	return e.err()
}

// Covers reports whether the granted scopes include every requested one.
func (t *RegistryToken) Covers(want []RegistryScope) bool {
	for _, w := range want {
		ok := false
		for _, g := range t.Scopes {
			if g.Repository != w.Repository {
				continue
			}
			ok = true
			for _, a := range w.Actions {
				if !slices.Contains(g.Actions, a) {
					ok = false
				}
			}
		}
		if !ok {
			return false
		}
	}
	return true
}

// ScopeString renders scopes in Docker token-spec form ("repository:x:pull,push").
func ScopeString(s RegistryScope) string {
	acts := make([]string, len(s.Actions))
	for i, a := range s.Actions {
		acts[i] = string(a)
	}
	return "repository:" + string(s.Repository) + ":" + strings.Join(acts, ",")
}

// TaskToken is the plaintext of a sealed task credential, e.g.
// BuildInputs.cloneToken: libsodium crypto_box_seal of this JSON object to
// the daemon's X25519 key. ServerID, TaskID and Purpose bind it to one field
// of one task on one server (no re-targeting).
type TaskToken struct {
	ServerID ServerID     `json:"serverId"`
	TaskID   TaskID       `json:"taskId"`
	Purpose  TokenPurpose `json:"purpose"`
	// Token is the credential (a GitHub App installation token for clones).
	Token secret.Secret `json:"token" jsonschema:"type=string,minLength=1,maxLength=4096"`
}

type taskTokenWire struct {
	ServerID ServerID     `json:"serverId"`
	TaskID   TaskID       `json:"taskId"`
	Purpose  TokenPurpose `json:"purpose"`
	Token    string       `json:"token"`
}

// MarshalJSON reveals the token deliberately: only used to produce plaintext that is sealed immediately.
func (t TaskToken) MarshalJSON() ([]byte, error) {
	return json.Marshal(taskTokenWire{ServerID: t.ServerID, TaskID: t.TaskID, Purpose: t.Purpose, Token: t.Token.Reveal()})
}

// UnmarshalJSON strictly decodes the wire form.
func (t *TaskToken) UnmarshalJSON(data []byte) error {
	var w taskTokenWire
	if err := json.Unmarshal(data, &w, strictOptions()); err != nil {
		return err
	}
	*t = TaskToken{ServerID: w.ServerID, TaskID: w.TaskID, Purpose: w.Purpose, Token: secret.Secret(w.Token)}
	return nil
}

// Validate checks ids and the token size. Errors never contain the token.
func (t *TaskToken) Validate() error {
	var e errs
	e.check("serverId", t.ServerID)
	e.check("taskId", t.TaskID)
	e.check("purpose", t.Purpose)
	tok := t.Token.Reveal()
	if tok == "" || len(tok) > 4096 || strings.ContainsAny(tok, "\x00\r\n") {
		e.addf("token", "must be 1-4096 bytes without NUL or line breaks")
	}
	return e.err()
}

// TaskEnv is the plaintext of BuildInputs.env: the build-scoped variables of
// one task, bound to the server and task.
type TaskEnv struct {
	ServerID ServerID `json:"serverId"`
	TaskID   TaskID   `json:"taskId"`
	// Env maps variable names to values ({} when empty). Values must not contain NUL bytes.
	Env map[string]secret.Secret `json:"env"`
}

type taskEnvWire struct {
	ServerID ServerID          `json:"serverId"`
	TaskID   TaskID            `json:"taskId"`
	Env      map[string]string `json:"env"`
}

// MarshalJSON reveals the values deliberately: only used to produce plaintext that is sealed immediately.
func (t TaskEnv) MarshalJSON() ([]byte, error) {
	env := make(map[string]string, len(t.Env))
	for k, v := range t.Env {
		env[k] = v.Reveal()
	}
	return json.Marshal(taskEnvWire{ServerID: t.ServerID, TaskID: t.TaskID, Env: env}, json.Deterministic(true))
}

// UnmarshalJSON strictly decodes the wire form.
func (t *TaskEnv) UnmarshalJSON(data []byte) error {
	var w taskEnvWire
	if err := json.Unmarshal(data, &w, strictOptions()); err != nil {
		return err
	}
	*t = TaskEnv{ServerID: w.ServerID, TaskID: w.TaskID}
	if w.Env != nil {
		t.Env = make(map[string]secret.Secret, len(w.Env))
		for k, v := range w.Env {
			t.Env[k] = secret.Secret(v)
		}
	}
	return nil
}

// Validate checks ids, names and values. Errors never contain values.
func (t *TaskEnv) Validate() error {
	var e errs
	e.check("serverId", t.ServerID)
	e.check("taskId", t.TaskID)
	checkSecretEnv(&e, t.Env)
	return e.err()
}
