package api

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"go/format"
	"os"
	"reflect"
	"slices"
	"sort"
	"strings"
	"testing"
	"unicode"
)

// The dashboard's Data classes own the API (docs/16 §2.4); the CLI is checked
// against the OpenAPI document they generate. Enum values are generated into
// enums_gen.go (`make gen-cli`), the hand-written models must match the
// schemas field by field.

var update = flag.Bool("update", false, "rewrite enums_gen.go from the OpenAPI document")

const specPath = "../../../dashboard/resources/api/openapi.json"

type spec struct {
	Paths   map[string]map[string]operation
	Schemas map[string]*schema
}

type operation struct {
	Parameters []struct {
		Name   string  `json:"name"`
		In     string  `json:"in"`
		Schema *schema `json:"schema"`
	} `json:"parameters"`
	Responses map[string]struct {
		Events map[string]*schema `json:"x-launchroom-events"`
	} `json:"responses"`
}

type schema struct {
	Type        json.RawMessage    `json:"type"`
	Ref         string             `json:"$ref"`
	Enum        []any              `json:"enum"`
	OneOf       []*schema          `json:"oneOf"`
	Items       *schema            `json:"items"`
	Properties  map[string]*schema `json:"properties"`
	Description string             `json:"description"`
	Tones       map[string]string  `json:"x-launchroom-tones"`
}

func (s *schema) types() []string {
	if len(s.Type) == 0 {
		return nil
	}
	var one string
	if json.Unmarshal(s.Type, &one) == nil {
		return []string{one}
	}
	var many []string
	_ = json.Unmarshal(s.Type, &many)
	return many
}

// nonNull returns the schema without its null alternative and whether null
// was allowed (`["string","null"]` or `oneOf: [X, {type: null}]`).
func (s *schema) nonNull() (*schema, bool) {
	if len(s.OneOf) > 0 {
		var rest []*schema
		nullable := false
		for _, o := range s.OneOf {
			if slices.Equal(o.types(), []string{"null"}) {
				nullable = true
				continue
			}
			rest = append(rest, o)
		}
		if len(rest) == 1 {
			return rest[0], nullable
		}
		return s, nullable
	}
	t := s.types()
	if slices.Contains(t, "null") {
		c := *s
		b, _ := json.Marshal(slices.DeleteFunc(slices.Clone(t), func(x string) bool { return x == "null" }))
		c.Type = b
		return &c, true
	}
	return s, false
}

func loadSpec(t *testing.T) *spec {
	t.Helper()
	raw, err := os.ReadFile(specPath)
	if err != nil {
		t.Fatalf("read the OpenAPI document: %v", err)
	}
	var doc struct {
		Paths      map[string]map[string]operation `json:"paths"`
		Components struct {
			Schemas map[string]*schema `json:"schemas"`
		} `json:"components"`
	}
	if err := json.Unmarshal(raw, &doc); err != nil {
		t.Fatal(err)
	}
	return &spec{Paths: doc.Paths, Schemas: doc.Components.Schemas}
}

func refName(ref string) string { return strings.TrimPrefix(ref, "#/components/schemas/") }

// parameterEnums are enums of query parameters; one typed by a named enum
// schema is generated from that schema.
var parameterEnums = []struct{ name, path, param string }{
	{"LogRange", "/environments/{environment}/logs", "range"},
	{"LogSource", "/environments/{environment}/logs", "source"},
	{"FindingStatus", "/security/findings", "status"},
}

func goName(s string) string {
	var b strings.Builder
	for _, part := range strings.FieldsFunc(s, func(r rune) bool { return !unicode.IsLetter(r) && !unicode.IsDigit(r) }) {
		r := []rune(part)
		r[0] = unicode.ToUpper(r[0])
		b.WriteString(string(r))
	}
	out := b.String()
	if strings.HasPrefix(out, "Api") {
		out = "API" + strings.TrimPrefix(out, "Api")
	}
	return out
}

func enumValues(t *testing.T, where string, s *schema) []string {
	t.Helper()
	s, _ = s.nonNull()
	out := make([]string, 0, len(s.Enum))
	for _, v := range s.Enum {
		str, ok := v.(string)
		if !ok {
			t.Fatalf("%s: enum value %v is not a string", where, v)
		}
		out = append(out, str)
	}
	return out
}

func renderEnums(t *testing.T, sp *spec) []byte {
	t.Helper()
	type enum struct {
		name, source string
		values       []string
		tones        map[string]string
	}
	var enums []enum
	for name, s := range sp.Schemas {
		if len(s.Enum) > 0 {
			enums = append(enums, enum{goName(name), "components.schemas." + name, enumValues(t, name, s), s.Tones})
		}
	}
	for _, p := range parameterEnums {
		op, ok := sp.Paths[p.path]["get"]
		if !ok {
			t.Fatalf("GET %s is not in the OpenAPI document", p.path)
		}
		found := false
		for _, prm := range op.Parameters {
			if prm.Name == p.param && prm.Schema != nil {
				found = true
				if ps, _ := prm.Schema.nonNull(); ps.Ref != "" {
					continue
				}
				values := enumValues(t, p.path+" "+p.param, prm.Schema)
				// A parameter typed by a named enum is generated from components.schemas once.
				if s, ok := sp.Schemas[p.name]; ok && len(s.Enum) > 0 {
					if !slices.Equal(values, enumValues(t, p.name, s)) {
						t.Fatalf("GET %s ?%s: its enum differs from components.schemas.%s", p.path, p.param, p.name)
					}
					continue
				}
				enums = append(enums, enum{p.name, "GET " + p.path + " ?" + p.param, values, nil})
			}
		}
		if !found {
			t.Fatalf("GET %s has no parameter %q", p.path, p.param)
		}
	}
	sort.Slice(enums, func(i, j int) bool { return enums[i].name < enums[j].name })

	var b bytes.Buffer
	b.WriteString("// Code generated by `make gen-cli` from dashboard/resources/api/openapi.json. DO NOT EDIT.\n\npackage api\n")
	seen := map[string]bool{}
	for _, e := range enums {
		fmt.Fprintf(&b, "\n// %s values (%s).\nconst (\n", e.name, e.source)
		for _, v := range e.values {
			c := e.name + goName(v)
			if seen[c] {
				t.Fatalf("generated constant %s twice", c)
			}
			seen[c] = true
			fmt.Fprintf(&b, "\t%s = %q\n", c, v)
		}
		fmt.Fprintf(&b, ")\n\n// %sValues lists every %s value in the API's order.\nvar %sValues = []string{", e.name, e.name, e.name)
		for i, v := range e.values {
			if i > 0 {
				b.WriteString(", ")
			}
			fmt.Fprintf(&b, "%s%s", e.name, goName(v))
		}
		b.WriteString("}\n")
		if len(e.tones) == 0 {
			continue
		}
		fmt.Fprintf(&b, "\n// %sTones is each %s value's status tone (the dashboard's StatusTone).\nvar %sTones = map[string]string{\n", e.name, e.name, e.name)
		for _, v := range e.values {
			tone, ok := e.tones[v]
			if !ok {
				t.Fatalf("%s: no tone for %q", e.source, v)
			}
			fmt.Fprintf(&b, "\t%s%s: %q,\n", e.name, goName(v), tone)
		}
		b.WriteString("}\n")
	}
	out, err := format.Source(b.Bytes())
	if err != nil {
		t.Fatalf("gofmt: %v\n%s", err, b.Bytes())
	}
	return out
}

func TestEnumsMatchOpenAPI(t *testing.T) {
	want := renderEnums(t, loadSpec(t))
	if *update {
		if err := os.WriteFile("enums_gen.go", want, 0o644); err != nil { //nolint:gosec // a source file
			t.Fatal(err)
		}
		return
	}
	got, err := os.ReadFile("enums_gen.go")
	if err != nil || !bytes.Equal(got, want) {
		t.Fatalf("enums_gen.go is out of date with the OpenAPI document: run `make gen-cli`")
	}
}

// models maps the CLI's types to the schemas they decode or send.
var models = map[reflect.Type]string{
	reflect.TypeFor[Token]():                 "ApiTokenData",
	reflect.TypeFor[User]():                  "ApiUserData",
	reflect.TypeFor[Team]():                  "ApiTeamData",
	reflect.TypeFor[Me]():                    "MeData",
	reflect.TypeFor[EnvironmentRef]():        "EnvironmentRefData",
	reflect.TypeFor[Project]():               "ProjectResource",
	reflect.TypeFor[Environment]():           "EnvironmentResource",
	reflect.TypeFor[Commit]():                "ApiCommitData",
	reflect.TypeFor[Failure]():               "ApiFailureData",
	reflect.TypeFor[Deployment]():            "DeploymentResource",
	reflect.TypeFor[Service]():               "ServiceResource",
	reflect.TypeFor[ServiceActionResult]():   "ServiceActionResultData",
	reflect.TypeFor[DeployResult]():          "DeployEnvironmentResultData",
	reflect.TypeFor[LogLine]():               "LogLineData",
	reflect.TypeFor[LogEntry]():              "LogEntryData",
	reflect.TypeFor[VariableTarget]():        "VariableTargetData",
	reflect.TypeFor[Variable]():              "VariableResource",
	reflect.TypeFor[ResolvedEnv]():           "ResolvedEnvData",
	reflect.TypeFor[LoginSession]():          "CliSessionData",
	reflect.TypeFor[LoginToken]():            "CliTokenData",
	reflect.TypeFor[ListMeta]():              "ListMeta",
	reflect.TypeFor[ConfigExport]():          "ConfigExportData",
	reflect.TypeFor[ConfigExcerptLine]():     "ConfigExcerptLineData",
	reflect.TypeFor[ConfigProblem]():         "ConfigFileProblemData",
	reflect.TypeFor[ChangeItem]():            "ChangeItem",
	reflect.TypeFor[ConfigCheck]():           "ConfigCheckData",
	reflect.TypeFor[SecurityServiceRef]():    "SecurityServiceRefData",
	reflect.TypeFor[SecurityFinding]():       "FindingCardData",
	reflect.TypeFor[InventoryUse]():          "InventoryUseData",
	reflect.TypeFor[InventoryPackage]():      "InventoryPackageData",
	reflect.TypeFor[InventorySearch]():       "InventorySearchData",
	reflect.TypeFor[Transfer]():              "TransferData",
	reflect.TypeFor[ChunkReceipt]():          "ChunkReceiptData",
	reflect.TypeFor[VolumeRef]():             "TransferVolumeData",
	reflect.TypeFor[ImportResult]():          "ImportResultData",
	reflect.TypeFor[ImportRun]():             "ImportRunData",
	reflect.TypeFor[ExportRun]():             "ExportRunData",
	reflect.TypeFor[DownloadLink]():          "DownloadLinkData",
	reflect.TypeFor[DeployRequest]():         "CreateDeploymentInput",
	reflect.TypeFor[CreateTransferRequest](): "CreateTransferInput",
	reflect.TypeFor[ImportRequest]():         "StartImportInput",
	reflect.TypeFor[ExportRequest]():         "StartExportInput",
	reflect.TypeFor[DeploymentLinesEvent]():  "DeploymentLinesEvent",
	reflect.TypeFor[DeploymentStatusEvent](): "DeploymentStatusEvent",
	reflect.TypeFor[EnvironmentLinesEvent](): "EnvironmentLinesEvent",
}

// streamEvents are the server-sent events the CLI decodes, by stream path.
var streamEvents = map[string]map[string]reflect.Type{
	"/deployments/{deployment}/logs/stream": {
		"lines":  reflect.TypeFor[DeploymentLinesEvent](),
		"status": reflect.TypeFor[DeploymentStatusEvent](),
	},
	"/environments/{environment}/logs/stream": {
		"lines": reflect.TypeFor[EnvironmentLinesEvent](),
	},
}

func TestStreamEventsMatchOpenAPI(t *testing.T) {
	sp := loadSpec(t)
	for path, events := range streamEvents {
		declared := sp.Paths[path]["get"].Responses["200"].Events
		for event, typ := range events {
			s, ok := declared[event]
			if !ok {
				t.Errorf("GET %s no longer sends the event %q", path, event)
				continue
			}
			if refName(s.Ref) != models[typ] {
				t.Errorf("GET %s event %q is %s, the CLI decodes %s", path, event, refName(s.Ref), typ.Name())
			}
		}
	}
}

func TestModelsMatchOpenAPI(t *testing.T) {
	sp := loadSpec(t)
	for typ, name := range models {
		s, ok := sp.Schemas[name]
		if !ok {
			t.Errorf("%s: schema %s is not in the OpenAPI document", typ.Name(), name)
			continue
		}
		for i := range typ.NumField() {
			f := typ.Field(i)
			tag, opts, _ := strings.Cut(f.Tag.Get("json"), ",")
			if tag == "" || tag == "-" {
				continue
			}
			where := typ.Name() + "." + f.Name
			prop, ok := s.Properties[tag]
			if !ok {
				t.Errorf("%s: %s has no property %q", where, name, tag)
				continue
			}
			checkField(t, sp, where, f.Type, prop, strings.Contains(opts, "omitempty"))
		}
	}
}

func checkField(t *testing.T, sp *spec, where string, typ reflect.Type, prop *schema, omitempty bool) {
	t.Helper()
	prop, nullable := prop.nonNull()
	if typ.Kind() == reflect.Pointer {
		typ = typ.Elem()
	} else if nullable && typ.Kind() != reflect.Slice && typ.Kind() != reflect.Map && !omitempty {
		t.Errorf("%s: the API may send null, use a pointer", where)
	}
	if prop.Ref != "" {
		target := sp.Schemas[refName(prop.Ref)]
		if target == nil {
			t.Errorf("%s: dangling $ref %s", where, prop.Ref)
			return
		}
		if len(target.Enum) > 0 {
			if typ.Kind() != reflect.String {
				t.Errorf("%s: %s is a string enum, the CLI has %s", where, refName(prop.Ref), typ)
			}
			return
		}
		if models[typ] != refName(prop.Ref) {
			t.Errorf("%s: the API sends %s, the CLI decodes %s (mapped to %q)", where, refName(prop.Ref), typ, models[typ])
		}
		return
	}
	want := map[reflect.Kind]string{
		reflect.String: "string", reflect.Bool: "boolean",
		reflect.Int: "integer", reflect.Int64: "integer", reflect.Int32: "integer",
		reflect.Float64: "number", reflect.Slice: "array", reflect.Map: "object",
	}[typ.Kind()]
	types := prop.types()
	matches := slices.Contains(types, want) || (want == "number" && slices.Contains(types, "integer"))
	if want == "" || !matches {
		t.Errorf("%s: the API sends %v, the CLI has %s", where, types, typ)
		return
	}
	if typ.Kind() == reflect.Slice && prop.Items != nil {
		checkField(t, sp, where+"[]", typ.Elem(), prop.Items, false)
	}
}

// Service statuses besides the deployment statuses, as ServiceResource
// describes them (the schema has no enum for the mix).
func TestServiceStatusesMatchOpenAPI(t *testing.T) {
	desc := loadSpec(t).Schemas["ServiceResource"].Description
	for _, s := range []string{ServiceStatusLive, ServiceStatusStopped, ServiceStatusNotDeployed} {
		if !strings.Contains(desc, "`"+s+"`") {
			t.Errorf("ServiceResource no longer documents the status %q:\n%s", s, desc)
		}
	}
}
