// Package dotenv writes `.env` files the dashboard's parser reads back
// verbatim (same rules as App\Support\Variables\EnvFileWriter): simple
// values unquoted, everything else double-quoted with \\ \" \n \r \t.
package dotenv

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
)

var (
	simple = regexp.MustCompile(`^[A-Za-z0-9_.,:/@+=%{}$-]*$`)
	keyRe  = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)
)

// ValidKey reports whether key is a variable name launchroom accepts.
func ValidKey(key string) bool { return len(key) <= 256 && keyRe.MatchString(key) }

// Line renders KEY=value.
func Line(key, value string) string {
	if simple.MatchString(value) && !strings.Contains(value, "${{") {
		return key + "=" + value
	}
	r := strings.NewReplacer(`\`, `\\`, `"`, `\"`, "\n", `\n`, "\r", `\r`, "\t", `\t`)
	return key + `="` + r.Replace(value) + `"`
}

// Render writes every variable, sorted by key, with a header comment.
func Render(vars map[string]string, header string) string {
	keys := make([]string, 0, len(vars))
	for k := range vars {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var b strings.Builder
	for _, line := range strings.Split(strings.TrimSpace(header), "\n") {
		if line != "" {
			b.WriteString("# " + line + "\n")
		}
	}
	for _, k := range keys {
		b.WriteString(Line(k, vars[k]) + "\n")
	}
	return b.String()
}

// Parse reads KEY=value lines (the subset Render writes, plus comments and
// single quotes), for tests and `env set` from a file.
func Parse(content string) (map[string]string, error) {
	out := map[string]string{}
	for n, raw := range strings.Split(content, "\n") {
		line := strings.TrimSpace(strings.TrimSuffix(raw, "\r"))
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimPrefix(line, "export ")
		key, value, ok := strings.Cut(line, "=")
		if !ok || !ValidKey(strings.TrimSpace(key)) {
			return nil, fmt.Errorf("line %d: expected KEY=value", n+1)
		}
		key = strings.TrimSpace(key)
		switch {
		case strings.HasPrefix(value, `"`):
			v, err := unquote(value)
			if err != nil {
				return nil, fmt.Errorf("line %d: %w", n+1, err)
			}
			value = v
		case strings.HasPrefix(value, `'`) && strings.HasSuffix(value, `'`) && len(value) >= 2:
			value = value[1 : len(value)-1]
		}
		out[key] = value
	}
	return out, nil
}

func unquote(s string) (string, error) {
	if len(s) < 2 || !strings.HasSuffix(s, `"`) {
		return "", fmt.Errorf("unterminated quote")
	}
	s = s[1 : len(s)-1]
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c != '\\' || i == len(s)-1 {
			b.WriteByte(c)
			continue
		}
		i++
		switch s[i] {
		case 'n':
			b.WriteByte('\n')
		case 'r':
			b.WriteByte('\r')
		case 't':
			b.WriteByte('\t')
		default:
			b.WriteByte(s[i])
		}
	}
	return b.String(), nil
}
