package state

import (
	"bytes"
	stdjson "encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"
)

const (
	schemaDir  = "../../../protocol/schema/v1"
	fixtureDir = "../../../protocol/fixtures/v1"
)

// decoders maps a schema file to the strict Go decoder for that document.
var decoders = map[string]func([]byte) error{
	"node-state.json":                 decodeAs[NodeState],
	"task.json":                       decodeAs[Task],
	"lease-request.json":              decodeAs[LeaseRequest],
	"task-lease.json":                 decodeAs[TaskLease],
	"task-heartbeat.json":             decodeAs[TaskHeartbeat],
	"task-heartbeat-result.json":      decodeAs[TaskHeartbeatResult],
	"task-completion.json":            decodeAs[TaskCompletion],
	"enroll-request.json":             decodeAs[EnrollRequest],
	"enroll-response.json":            decodeAs[EnrollResponse],
	"report.json":                     decodeAs[Report],
	"log-batch.json":                  decodeAs[LogBatch],
	"workload-env.json":               decodeAs[WorkloadEnv],
	"registry-token-request.json":     decodeAs[RegistryTokenRequest],
	"registry-token.json":             decodeAs[RegistryToken],
	"task-token.json":                 decodeAs[TaskToken],
	"task-env.json":                   decodeAs[TaskEnv],
	"ingress-secret.json":             decodeAs[IngressSecret],
	"mesh-auth-key.json":              decodeAs[MeshAuthKey],
	"daemon-config.json":              decodeAs[DaemonConfig],
	"broadcast-auth-request.json":     decodeAs[BroadcastAuthRequest],
	"broadcast-auth.json":             decodeAs[BroadcastAuth],
	"session-command.json":            decodeAs[SessionCommand],
	"client-certificate-request.json": decodeAs[ClientCertificateRequest],
	"client-certificate.json":         decodeAs[ClientCertificate],
	// Not a document: one capability of the policy floor's allow-list
	// (generated for the dashboard, docs/03 §8).
	"capability-allow-list.json": decodeCapability,
}

func decodeCapability(data []byte) error {
	var c Capability
	if err := stdjson.Unmarshal(data, &c); err != nil {
		return err
	}
	return c.Validate()
}

func decodeAs[T any, PT interface {
	*T
	Document
}](data []byte) error {
	_, err := Decode[T, PT](data)
	return err
}

func compileSchemas(t *testing.T) map[string]*jsonschema.Schema {
	t.Helper()
	c := jsonschema.NewCompiler()
	entries, err := os.ReadDir(schemaDir)
	if err != nil {
		t.Fatal(err)
	}
	urls := map[string]string{}
	for _, e := range entries {
		raw, err := os.ReadFile(filepath.Join(schemaDir, e.Name())) //nolint:gosec // test fixture path
		if err != nil {
			t.Fatal(err)
		}
		doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
		if err != nil {
			t.Fatalf("%s: %v", e.Name(), err)
		}
		url := "https://launchroom.vantezzen.io/schema/v1/" + e.Name()
		if err := c.AddResource(url, doc); err != nil {
			t.Fatal(err)
		}
		urls[e.Name()] = url
	}
	out := map[string]*jsonschema.Schema{}
	for name, url := range urls {
		s, err := c.Compile(url)
		if err != nil {
			t.Fatalf("compile %s: %v", name, err)
		}
		out[name] = s
	}
	if len(out) != len(decoders) {
		t.Fatalf("schema dir has %d schemas, decoders know %d", len(out), len(decoders))
	}
	return out
}

func schemaValidate(s *jsonschema.Schema, data []byte) error {
	inst, err := jsonschema.UnmarshalJSON(bytes.NewReader(data))
	if err != nil {
		return err
	}
	return s.Validate(inst)
}

// TestFixturesMatchTypesAndSchema decodes every shared fixture strictly in Go
// and validates it against the generated schema, which is what the PHP side
// also does — so both sides agree on the same documents.
func TestFixturesMatchTypesAndSchema(t *testing.T) {
	schemas := compileSchemas(t)
	entries, err := os.ReadDir(fixtureDir)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) == 0 {
		t.Fatal("no fixtures")
	}
	for _, e := range entries {
		t.Run(e.Name(), func(t *testing.T) {
			schemaName, _, _ := strings.Cut(e.Name(), ".")
			schemaName += ".json"
			dec, ok := decoders[schemaName]
			if !ok {
				t.Fatalf("no schema %s for fixture (name fixtures <schema>.<variant>.json)", schemaName)
			}
			data, err := os.ReadFile(filepath.Join(fixtureDir, e.Name())) //nolint:gosec // test fixture path
			if err != nil {
				t.Fatal(err)
			}
			if err := dec(data); err != nil {
				t.Fatalf("Go strict decode: %v", err)
			}
			if err := schemaValidate(schemas[schemaName], data); err != nil {
				t.Fatalf("schema: %v", err)
			}
		})
	}
}

// TestSchemaRejectsWhatGoRejects spot-checks that schema and Go agree on
// common mistakes the PHP side could make.
func TestSchemaRejectsWhatGoRejects(t *testing.T) {
	schemas := compileSchemas(t)
	base := readFixture(t, "node-state.web-postgres.json")
	web := func(d map[string]any) map[string]any { return d["workloads"].([]any)[0].(map[string]any) }
	pg := func(d map[string]any) map[string]any { return d["workloads"].([]any)[1].(map[string]any) }
	route := func(d map[string]any) map[string]any { return d["routes"].([]any)[0].(map[string]any) }
	cases := map[string]func(d map[string]any){
		"unknown top-level field": func(d map[string]any) { d["extra"] = 1 },
		"tagged image":            func(d map[string]any) { web(d)["image"] = "127.0.0.1:5000/apps/web:latest" },
		"tag plus digest": func(d map[string]any) {
			web(d)["image"] = "127.0.0.1:5000/apps/web:1@sha256:" + strings.Repeat("a", 64)
		},
		"implicit registry":       func(d map[string]any) { web(d)["image"] = "library/web@sha256:" + strings.Repeat("a", 64) },
		"wrong kind":              func(d map[string]any) { d["kind"] = "Nodestate" },
		"wrong apiVersion":        func(d map[string]any) { d["apiVersion"] = "launchroom.vantezzen.io/v2" },
		"lowercase id":            func(d map[string]any) { d["serverId"] = strings.ToLower(d["serverId"].(string)) },
		"empty args":              func(d map[string]any) { web(d)["args"] = []any{} },
		"bad restart":             func(d map[string]any) { web(d)["restart"] = "sometimes" },
		"relative mount target":   func(d map[string]any) { pg(d)["mounts"].([]any)[0].(map[string]any)["target"] = "var/lib" },
		"bind mount source":       func(d map[string]any) { pg(d)["mounts"].([]any)[0].(map[string]any)["source"] = "/etc" },
		"port out of range":       func(d map[string]any) { web(d)["port"] = 70000 },
		"privileged smuggled":     func(d map[string]any) { web(d)["privileged"] = true },
		"health command smuggled": func(d map[string]any) { web(d)["health"].(map[string]any)["command"] = "curl x" },
		"version zero":            func(d map[string]any) { d["version"] = 0 },
		"route without upstreams": func(d map[string]any) { d["routes"].([]any)[0].(map[string]any)["upstreams"] = []any{} },
		"compound duration":       func(d map[string]any) { web(d)["health"].(map[string]any)["startPeriod"] = "1m30s" },
		"capability with prefix":  func(d map[string]any) { pg(d)["capabilities"] = []any{"CAP_CHOWN"} },
		"no networks":             func(d map[string]any) { web(d)["networks"] = []any{} },
		"bad network name":        func(d map[string]any) { d["networks"].([]any)[0].(map[string]any)["name"] = "my-net" },
		"uppercase hostname":      func(d map[string]any) { d["routes"].([]any)[0].(map[string]any)["hosts"] = []any{"Shop.example.com"} },
		"missing env":             func(d map[string]any) { delete(web(d), "env") },
		"upstream as string":      func(d map[string]any) { route(d)["upstreams"] = []any{"10.231.4.5:3000"} },
		"upstream both forms": func(d map[string]any) {
			route(d)["upstreams"] = []any{map[string]any{"workload": "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7", "port": 3000, "address": "10.0.0.1:80"}}
		},
		"upstream workload without port": func(d map[string]any) {
			route(d)["upstreams"] = []any{map[string]any{"workload": "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7"}}
		},
		"upstream address with port": func(d map[string]any) {
			route(d)["upstreams"] = []any{map[string]any{"address": "10.0.0.1:80", "port": 80}}
		},
		"header value placeholder": func(d map[string]any) { route(d)["headers"] = map[string]any{"X-Robots-Tag": "{env.SECRET}"} },
		"header name with space":   func(d map[string]any) { route(d)["headers"] = map[string]any{"X Robots": "noindex"} },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			var d map[string]any
			if err := stdjson.Unmarshal([]byte(base), &d); err != nil {
				t.Fatal(err)
			}
			mutate(d)
			doc, err := stdjson.Marshal(d)
			if err != nil {
				t.Fatal(err)
			}
			if err := decoders["node-state.json"](doc); err == nil {
				t.Error("Go accepted")
			}
			if err := schemaValidate(schemas["node-state.json"], doc); err == nil {
				t.Error("schema accepted")
			}
		})
	}
}

func readFixture(t *testing.T, name string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(fixtureDir, name)) //nolint:gosec // test fixture path
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}
