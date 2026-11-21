package mcp

import (
	"encoding/json"
	"fmt"
	"math"
	"reflect"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"unicode/utf8"
)

// Schema is the JSON Schema subset tool inputs and outputs use. It is
// generated from Go structs (SchemaFor), so a tool's schema and the values
// it accepts or answers can't drift apart.
type Schema struct {
	Type                 any                `json:"type,omitempty"` // a type name or a list of them
	Description          string             `json:"description,omitempty"`
	Properties           map[string]*Schema `json:"properties,omitempty"`
	Required             []string           `json:"required,omitempty"`
	AdditionalProperties any                `json:"additionalProperties,omitempty"` // false or a *Schema
	Items                *Schema            `json:"items,omitempty"`
	Enum                 []any              `json:"enum,omitempty"`
	Pattern              string             `json:"pattern,omitempty"`
	Minimum              *float64           `json:"minimum,omitempty"`
	Maximum              *float64           `json:"maximum,omitempty"`
	MinLength            *int               `json:"minLength,omitempty"`
	MaxLength            *int               `json:"maxLength,omitempty"`
	MinItems             *int               `json:"minItems,omitempty"`
	MaxItems             *int               `json:"maxItems,omitempty"`
}

// SchemaFor describes the JSON encoding of v's type. Struct fields use their
// `json` name; a field is required unless it is a pointer or `omitempty`.
// A pointer, slice or map that can encode as null without `omitempty` also
// allows null. Tags refine a field: `desc`, `enum` (comma separated),
// `pattern`, `min`, `max` (numbers), `minLength`, `maxLength`, `minItems`,
// `maxItems`.
func SchemaFor(v any) *Schema {
	return schemaOf(reflect.TypeOf(v), map[reflect.Type]bool{})
}

func schemaOf(t reflect.Type, seen map[reflect.Type]bool) *Schema {
	nullable := false
	for t.Kind() == reflect.Pointer {
		t = t.Elem()
		nullable = true
	}
	s := &Schema{}
	switch t.Kind() {
	case reflect.Bool:
		s.Type = "boolean"
	case reflect.Int, reflect.Int8, reflect.Int16, reflect.Int32, reflect.Int64,
		reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uint64:
		s.Type = "integer"
	case reflect.Float32, reflect.Float64:
		s.Type = "number"
	case reflect.String:
		s.Type = "string"
	case reflect.Slice, reflect.Array:
		if t == reflect.TypeOf(json.RawMessage(nil)) {
			return &Schema{}
		}
		s.Type = "array"
		s.Items = schemaOf(t.Elem(), seen)
	case reflect.Map:
		s.Type = "object"
		s.AdditionalProperties = schemaOf(t.Elem(), seen)
	case reflect.Struct:
		if seen[t] {
			panic("mcp: recursive type " + t.String())
		}
		seen[t] = true
		s.Type = "object"
		s.Properties = map[string]*Schema{}
		s.AdditionalProperties = false
		addFields(s, t, seen)
		delete(seen, t)
		sort.Strings(s.Required)
	case reflect.Interface:
		return &Schema{}
	default:
		panic("mcp: no schema for " + t.String())
	}
	if nullable {
		s.Type = []string{s.Type.(string), "null"}
	}
	return s
}

func addFields(s *Schema, t reflect.Type, seen map[reflect.Type]bool) {
	for i := range t.NumField() {
		f := t.Field(i)
		if !f.IsExported() {
			continue
		}
		name, opts, _ := strings.Cut(f.Tag.Get("json"), ",")
		if name == "-" {
			continue
		}
		if f.Anonymous && name == "" && f.Type.Kind() == reflect.Struct {
			addFields(s, f.Type, seen)
			continue
		}
		if name == "" {
			name = f.Name
		}
		omitempty := strings.Contains(opts, "omitempty") || strings.Contains(opts, "omitzero")
		prop := schemaOf(f.Type, seen)
		k := f.Type.Kind()
		if !omitempty && (k == reflect.Slice || k == reflect.Map) && prop.Type != nil {
			prop.Type = []string{prop.Type.(string), "null"}
		}
		applyTags(prop, f.Tag)
		s.Properties[name] = prop
		if !omitempty && k != reflect.Pointer {
			s.Required = append(s.Required, name)
		}
	}
}

func applyTags(s *Schema, tag reflect.StructTag) {
	s.Description = tag.Get("desc")
	if e := tag.Get("enum"); e != "" {
		for _, v := range strings.Split(e, ",") {
			s.Enum = append(s.Enum, v)
		}
	}
	s.Pattern = tag.Get("pattern")
	s.Minimum = floatTag(tag, "min")
	s.Maximum = floatTag(tag, "max")
	s.MinLength = intTag(tag, "minLength")
	s.MaxLength = intTag(tag, "maxLength")
	s.MinItems = intTag(tag, "minItems")
	s.MaxItems = intTag(tag, "maxItems")
}

func floatTag(tag reflect.StructTag, key string) *float64 {
	v, ok := tag.Lookup(key)
	if !ok {
		return nil
	}
	f, err := strconv.ParseFloat(v, 64)
	if err != nil {
		panic("mcp: bad " + key + " tag " + v)
	}
	return &f
}

func intTag(tag reflect.StructTag, key string) *int {
	f := floatTag(tag, key)
	if f == nil {
		return nil
	}
	n := int(*f)
	return &n
}

// types lists the schema's type names.
func (s *Schema) types() []string {
	switch t := s.Type.(type) {
	case string:
		return []string{t}
	case []string:
		return t
	}
	return nil
}

var patterns sync.Map // pattern → *regexp.Regexp

func compiled(p string) *regexp.Regexp {
	if re, ok := patterns.Load(p); ok {
		return re.(*regexp.Regexp)
	}
	re := regexp.MustCompile(p)
	patterns.Store(p, re)
	return re
}

// Validate checks a decoded JSON value (json.Decoder with UseNumber, or
// plain float64 numbers) against s and returns the first problem, worded for
// a model: "service: must be a string".
func (s *Schema) Validate(v any) error {
	return s.validate("", v)
}

func (s *Schema) validate(path string, v any) error {
	at := func(format string, args ...any) error {
		msg := fmt.Sprintf(format, args...)
		if path == "" {
			return fmt.Errorf("arguments %s", msg)
		}
		return fmt.Errorf("%s: %s", path, msg)
	}
	if types := s.types(); len(types) > 0 && !matchesType(types, v) {
		return at("must be %s", strings.Join(types, " or "))
	}
	if len(s.Enum) > 0 {
		found := false
		for _, e := range s.Enum {
			if fmt.Sprint(e) == fmt.Sprint(v) {
				found = true
				break
			}
		}
		if !found {
			return at("must be one of %s", joinEnum(s.Enum))
		}
	}
	switch x := v.(type) {
	case string:
		n := utf8.RuneCountInString(x)
		if s.MinLength != nil && n < *s.MinLength {
			return at("must be at least %d characters", *s.MinLength)
		}
		if s.MaxLength != nil && n > *s.MaxLength {
			return at("must be at most %d characters", *s.MaxLength)
		}
		if s.Pattern != "" && !compiled(s.Pattern).MatchString(x) {
			return at("%q doesn't match %s", truncateForError(x), s.Pattern)
		}
	case json.Number, float64:
		f := toFloat(x)
		if s.Minimum != nil && f < *s.Minimum {
			return at("must be at least %s", strconv.FormatFloat(*s.Minimum, 'f', -1, 64))
		}
		if s.Maximum != nil && f > *s.Maximum {
			return at("must be at most %s", strconv.FormatFloat(*s.Maximum, 'f', -1, 64))
		}
	case []any:
		if s.MinItems != nil && len(x) < *s.MinItems {
			return at("needs at least %d items", *s.MinItems)
		}
		if s.MaxItems != nil && len(x) > *s.MaxItems {
			return at("takes at most %d items", *s.MaxItems)
		}
		if s.Items != nil {
			for i, item := range x {
				if err := s.Items.validate(fmt.Sprintf("%s[%d]", path, i), item); err != nil {
					return err
				}
			}
		}
	case map[string]any:
		for _, name := range s.Required {
			if _, ok := x[name]; !ok {
				return at("missing %q", name)
			}
		}
		keys := make([]string, 0, len(x))
		for k := range x {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			child := join(path, k)
			if p, ok := s.Properties[k]; ok {
				if err := p.validate(child, x[k]); err != nil {
					return err
				}
				continue
			}
			switch extra := s.AdditionalProperties.(type) {
			case bool:
				if !extra {
					return at("unknown %q (known: %s)", k, strings.Join(s.propertyNames(), ", "))
				}
			case *Schema:
				if err := extra.validate(child, x[k]); err != nil {
					return err
				}
			}
		}
	}
	return nil
}

func (s *Schema) propertyNames() []string {
	names := make([]string, 0, len(s.Properties))
	for k := range s.Properties {
		names = append(names, k)
	}
	sort.Strings(names)
	return names
}

func join(path, key string) string {
	if path == "" {
		return key
	}
	return path + "." + key
}

func joinEnum(values []any) string {
	parts := make([]string, len(values))
	for i, v := range values {
		parts[i] = fmt.Sprint(v)
	}
	return strings.Join(parts, ", ")
}

func truncateForError(s string) string {
	if len(s) > 60 {
		return s[:60] + "…"
	}
	return s
}

func toFloat(v any) float64 {
	switch x := v.(type) {
	case json.Number:
		f, _ := x.Float64()
		return f
	case float64:
		return x
	}
	return math.NaN()
}

func matchesType(types []string, v any) bool {
	for _, t := range types {
		switch t {
		case "null":
			if v == nil {
				return true
			}
		case "boolean":
			if _, ok := v.(bool); ok {
				return true
			}
		case "string":
			if _, ok := v.(string); ok {
				return true
			}
		case "number":
			if _, ok := v.(json.Number); ok {
				return true
			}
			if _, ok := v.(float64); ok {
				return true
			}
		case "integer":
			if isInteger(v) {
				return true
			}
		case "array":
			if _, ok := v.([]any); ok {
				return true
			}
		case "object":
			if _, ok := v.(map[string]any); ok {
				return true
			}
		}
	}
	return false
}

func isInteger(v any) bool {
	switch x := v.(type) {
	case json.Number:
		if _, err := x.Int64(); err == nil {
			return true
		}
		f, err := x.Float64()
		return err == nil && f == math.Trunc(f) && !strings.ContainsAny(string(x), "eE.")
	case float64:
		return x == math.Trunc(x)
	}
	return false
}

// decodeJSON decodes raw into a generic value with json.Number numbers.
func decodeJSON(raw []byte) (any, error) {
	dec := json.NewDecoder(strings.NewReader(string(raw)))
	dec.UseNumber()
	var v any
	if err := dec.Decode(&v); err != nil {
		return nil, err
	}
	return v, nil
}
