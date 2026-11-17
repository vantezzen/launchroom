package doctor

import (
	"io/fs"
	"os"
	"regexp"
	"sort"
	"strings"
	"syscall"

	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// Redactor removes secrets from text that goes into a support bundle or
// onto the screen: every value of .env (8 characters or longer) by name,
// PEM blocks, Authorization headers and bearer tokens, URL credentials,
// password=/secret=/token= pairs and JWT-shaped strings.
type Redactor struct {
	values []envValue
}

type envValue struct{ key, value string }

// NewRedactor knows the values of the given .env (none when it can't be read).
func NewRedactor(env map[string]string) *Redactor {
	r := &Redactor{}
	for k, v := range env {
		if len(v) >= 8 {
			r.values = append(r.values, envValue{k, v})
		}
	}
	// Longest first, so a value that contains another is replaced whole.
	sort.Slice(r.values, func(i, j int) bool {
		if len(r.values[i].value) != len(r.values[j].value) {
			return len(r.values[i].value) > len(r.values[j].value)
		}
		return r.values[i].key < r.values[j].key
	})
	return r
}

var (
	rePEM        = regexp.MustCompile(`(?s)-----BEGIN [A-Z0-9 ]+-----.*?-----END [A-Z0-9 ]+-----`)
	reAuthHeader = regexp.MustCompile(`(?i)(authorization|proxy-authorization|x-registry-auth|cookie|set-cookie)(["']?\s*[:=]\s*["']?)[^\r\n"']+`)
	reBearer     = regexp.MustCompile(`(?i)\b(bearer|basic|token)\s+[A-Za-z0-9._~+/=-]{8,}`)
	reURLCreds   = regexp.MustCompile(`([a-zA-Z][a-zA-Z0-9+.-]*://)[^/\s:@"']+:[^/\s@"']+@`)
	reKVSecret   = regexp.MustCompile(`(?i)\b([a-z0-9_.-]*(?:password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key)[a-z0-9_.-]*)(["']?\s*[:=]\s*["']?)([^\s"'&,;}]{4,})`)
	reJWT        = regexp.MustCompile(`\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b`)
	// Launchroom's own token shapes (join tokens, API tokens).
	reLRToken = regexp.MustCompile(`\blr_[a-z]{2,4}_[A-Za-z0-9]{16,}\b`)
)

// Redact returns s without the secrets it knows or recognises.
func (r *Redactor) Redact(s string) string {
	for _, v := range r.values {
		s = strings.ReplaceAll(s, v.value, "[redacted:"+v.key+"]")
	}
	return redactPatterns(s)
}

func redactPatterns(s string) string {
	s = rePEM.ReplaceAllString(s, "[redacted:pem]")
	s = reAuthHeader.ReplaceAllString(s, "${1}${2}[redacted]")
	s = reBearer.ReplaceAllString(s, "${1} [redacted]")
	s = reURLCreds.ReplaceAllString(s, "${1}[redacted]@")
	s = reKVSecret.ReplaceAllStringFunc(s, func(m string) string {
		if strings.Contains(m, "[redacted") {
			return m
		}
		sub := reKVSecret.FindStringSubmatch(m)
		return sub[1] + sub[2] + "[redacted]"
	})
	s = reJWT.ReplaceAllString(s, "[redacted:jwt]")
	return reLRToken.ReplaceAllString(s, "[redacted:token]")
}

// redactLine is the pattern-only redaction for single lines shown on screen.
func redactLine(s string) string { return redactPatterns(s) }

func migrationFailure(logs string) string { return update.MigrationFailure(logs) }

// chownLike gives path the owner of info (best effort: the doctor runs as
// root on the control plane).
func chownLike(path string, info fs.FileInfo) {
	if st, ok := info.Sys().(*syscall.Stat_t); ok {
		_ = os.Lchown(path, int(st.Uid), int(st.Gid))
	}
}
