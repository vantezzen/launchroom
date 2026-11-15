// Command schemagen emits the JSON Schemas of the v1 daemon protocol from the
// Go types in internal/state into protocol/schema/v1 (docs/10 §5).
//
// Usage (from daemon/): go run ./cmd/schemagen -out ../protocol/schema/v1
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"path"
	"path/filepath"
	"reflect"
	"sort"
	"strconv"

	"github.com/invopop/jsonschema"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	modulePath = "github.com/vantezzen/launchroom/daemon"
	schemaBase = "https://launchroom.vantezzen.io/schema/v1/"
)

func isTask(v any) bool { _, ok := v.(*state.Task); return ok }

func main() {
	out := flag.String("out", "../protocol/schema/v1", "output directory")
	root := flag.String("module-root", ".", "path to the daemon module root (relative to the working directory)")
	flag.Parse()

	files, err := Generate(*root)
	if err != nil {
		fmt.Fprintln(os.Stderr, "schemagen:", err)
		os.Exit(1)
	}
	if err := os.MkdirAll(*out, 0o750); err != nil {
		fmt.Fprintln(os.Stderr, "schemagen:", err)
		os.Exit(1)
	}
	for name, data := range files {
		if err := os.WriteFile(filepath.Join(*out, name), data, 0o644); err != nil { //nolint:gosec // schemas are public
			fmt.Fprintln(os.Stderr, "schemagen:", err)
			os.Exit(1)
		}
	}
	fmt.Printf("schemagen: wrote %d schemas to %s\n", len(files), *out)
}

// Generate returns file name → schema bytes. moduleRoot must be a relative
// path from the working directory to the daemon module root (Go doc comments
// become schema descriptions).
func Generate(moduleRoot string) (map[string][]byte, error) {
	if filepath.IsAbs(moduleRoot) {
		return nil, errors.New("module root must be relative to the working directory")
	}
	r, err := newReflector(moduleRoot)
	if err != nil {
		return nil, err
	}

	out := make(map[string][]byte)
	for _, d := range state.Documents() {
		s := r.Reflect(d.Value)
		s.ID = jsonschema.ID(schemaBase + d.File)
		s.Title = d.Title
		if d.Kind != "" {
			setConst(s, "apiVersion", state.APIVersion)
			setConst(s, "kind", d.Kind)
		}
		if err := patchTaskUnion(r, s, isTask(d.Value)); err != nil {
			return nil, err
		}
		patchRouteUpstream(s)
		patchMapKeys(s)
		data, err := marshal(s)
		if err != nil {
			return nil, fmt.Errorf("%s: %w", d.File, err)
		}
		out[d.File] = data
	}
	caps, err := capabilityAllowList()
	if err != nil {
		return nil, err
	}
	out[capabilityAllowListFile] = caps
	vocab, err := json.MarshalIndent(vocabulary(), "", "  ")
	if err != nil {
		return nil, err
	}
	out[vocabularyFile] = append(vocab, '\n')
	return out, nil
}

// vocabulary is state's vocabulary plus the host-side facts other packages
// own: the generated network/volume name prefixes and the mesh range.
func vocabulary() state.Vocabulary {
	v := state.ProtocolVocabulary()
	v.Constants["networkPrefix"] = names.NetworkPrefix
	v.Constants["volumePrefix"] = names.VolumePrefix
	v.Constants["meshRange"] = policy.MeshRange.String()
	return v
}

// vocabularyFile holds the grammars, limits, features and the field/task →
// feature table the dashboard checks against (state.Vocabulary).
const vocabularyFile = "vocabulary.json"

// capabilityAllowListFile is the policy floor's default capability
// allow-list (docs/03 §8) as a schema of one grantable capability. The
// dashboard offers exactly these (its tests compare its list to this file).
const capabilityAllowListFile = "capability-allow-list.json"

func capabilityAllowList() ([]byte, error) {
	caps := make([]string, 0, len(policy.DefaultCapabilities))
	for _, c := range policy.DefaultCapabilities {
		caps = append(caps, string(c))
	}
	sort.Strings(caps)
	enum := make([]any, len(caps))
	for i, c := range caps {
		enum[i] = c
	}
	return marshal(&jsonschema.Schema{
		Version:     jsonschema.Version,
		ID:          jsonschema.ID(schemaBase + capabilityAllowListFile),
		Title:       "Capability: one Linux capability launchroomd's default policy floor lets a workload add (docs/03 §8)",
		Description: "WorkloadSpec.capabilities outside this list are refused unless the server's local policy.yaml allows them.",
		Type:        "string",
		Enum:        enum,
	})
}

func newReflector(moduleRoot string) (*jsonschema.Reflector, error) {
	r := &jsonschema.Reflector{
		Anonymous:      true,
		ExpandedStruct: true,
		Mapper:         mapper,
	}
	wd, err := os.Getwd()
	if err != nil {
		return nil, err
	}
	absRoot, err := filepath.Abs(moduleRoot)
	if err != nil {
		return nil, err
	}
	rel, err := filepath.Rel(absRoot, wd)
	if err != nil {
		return nil, err
	}
	// AddGoComments keys comments by path.Join(base, <walked dir>); with a
	// relative walk path this resolves back to the real import path.
	base := path.Join(modulePath, filepath.ToSlash(rel))
	if err := r.AddGoComments(base, filepath.Join(moduleRoot, "internal", "state")); err != nil {
		return nil, fmt.Errorf("read doc comments: %w", err)
	}
	return r, nil
}

var (
	describerType = reflect.TypeFor[state.SchemaDescriber]()
	secretType    = reflect.TypeFor[secret.Secret]()
)

// mapper turns protocol scalar types into schemas from their ProtocolSchema.
func mapper(t reflect.Type) *jsonschema.Schema {
	if t == secretType {
		return &jsonschema.Schema{Type: "string"}
	}
	if !t.Implements(describerType) {
		return nil
	}
	d, _ := reflect.Zero(t).Interface().(state.SchemaDescriber)
	ps := d.ProtocolSchema()
	s := &jsonschema.Schema{Type: ps.Type, Pattern: ps.Pattern, Format: ps.Format}
	for _, e := range ps.Enum {
		s.Enum = append(s.Enum, e)
	}
	if ps.Minimum != nil {
		s.Minimum = json.Number(strconv.FormatInt(*ps.Minimum, 10))
	}
	if ps.Maximum != nil {
		s.Maximum = json.Number(strconv.FormatInt(*ps.Maximum, 10))
	}
	if ps.MinLength > 0 {
		v := uint64(ps.MinLength) //nolint:gosec // positive by construction
		s.MinLength = &v
	}
	if ps.MaxLength > 0 {
		v := uint64(ps.MaxLength) //nolint:gosec // positive by construction
		s.MaxLength = &v
	}
	return s
}

func setConst(s *jsonschema.Schema, prop string, v string) {
	if s.Properties == nil {
		return
	}
	if p, ok := s.Properties.Get(prop); ok {
		p.Type = "string"
		p.Const = v
	}
}

// patchTaskUnion turns Task.spec into a discriminated union: for every task
// type, `if type == X then spec is XSpec`. It applies to the root (task.json)
// or to the Task definition referenced from other documents.
func patchTaskUnion(r *jsonschema.Reflector, root *jsonschema.Schema, rootIsTask bool) error {
	var target *jsonschema.Schema
	switch {
	case rootIsTask:
		target = root
	case root.Definitions["Task"] != nil:
		target = root.Definitions["Task"]
	default:
		return nil
	}
	if root.Definitions == nil {
		root.Definitions = jsonschema.Definitions{}
	}

	specs := state.SpecTypes()
	types := make([]string, 0, len(specs))
	for t := range specs {
		types = append(types, string(t))
	}
	sort.Strings(types)

	for _, t := range types {
		spec := specs[state.TaskType(t)]
		name := reflect.TypeOf(spec).Elem().Name()
		sub := withoutExpanded(r).Reflect(spec)
		for k, v := range sub.Definitions {
			root.Definitions[k] = v
		}
		if root.Definitions[name] == nil {
			return fmt.Errorf("spec type %s missing from definitions", name)
		}
		ifProps := jsonschema.NewProperties()
		ifProps.Set("type", &jsonschema.Schema{Const: t})
		thenProps := jsonschema.NewProperties()
		thenProps.Set("spec", &jsonschema.Schema{Ref: "#/$defs/" + name})
		target.AllOf = append(target.AllOf, &jsonschema.Schema{
			If:   &jsonschema.Schema{Properties: ifProps, Required: []string{"type"}},
			Then: &jsonschema.Schema{Properties: thenProps},
		})
	}
	if p, ok := target.Properties.Get("spec"); ok {
		p.Type = "object"
		p.Description = "Typed spec selected by type (see allOf)."
	}
	return nil
}

// patchRouteUpstream makes RouteUpstream an exclusive union: either
// {workload, port} or {address}.
func patchRouteUpstream(root *jsonschema.Schema) {
	def := root.Definitions["RouteUpstream"]
	if def == nil {
		return
	}
	forbid := func(names ...string) *jsonschema.Schema {
		alts := make([]*jsonschema.Schema, 0, len(names))
		for _, n := range names {
			alts = append(alts, &jsonschema.Schema{Required: []string{n}})
		}
		return &jsonschema.Schema{AnyOf: alts}
	}
	def.OneOf = []*jsonschema.Schema{
		{Required: []string{"workload", "port"}, Not: forbid("address")},
		{Required: []string{"address"}, Not: forbid("workload", "port")},
	}
}

// patchMapKeys adds propertyNames patterns to maps whose keys have a grammar.
func patchMapKeys(root *jsonschema.Schema) {
	if root.Properties != nil {
		if env, ok := root.Properties.Get("env"); ok && root.Definitions["WorkloadEnv"] == nil && env.AdditionalProperties != nil {
			env.PropertyNames = &jsonschema.Schema{Type: "string", Pattern: state.EnvNamePattern}
		}
	}
	if def := root.Definitions["RouteSpec"]; def != nil && def.Properties != nil {
		if h, ok := def.Properties.Get("headers"); ok {
			for _, alt := range h.OneOf {
				if alt.Type == "object" {
					alt.PropertyNames = &jsonschema.Schema{Type: "string", Pattern: state.HeaderName("").ProtocolSchema().Pattern}
					if alt.AdditionalProperties != nil {
						alt.AdditionalProperties.Pattern = "^[^{}]*$"
					}
				}
			}
		}
	}
}

func withoutExpanded(r *jsonschema.Reflector) *jsonschema.Reflector {
	c := *r
	c.ExpandedStruct = false
	return &c
}

func marshal(s *jsonschema.Schema) ([]byte, error) {
	raw, err := json.Marshal(s)
	if err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	if err := json.Indent(&buf, raw, "", "  "); err != nil {
		return nil, err
	}
	buf.WriteByte('\n')
	return buf.Bytes(), nil
}
