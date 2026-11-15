package secret

import (
	"encoding/base64"
	"net/url"
	"strings"
	"testing"
)

func TestMasker(t *testing.T) {
	const s = "s3cr3t/value+x=="
	m := NewMasker(s, "abc", "")
	for _, form := range []string{
		s,
		base64.StdEncoding.EncodeToString([]byte(s)),
		base64.URLEncoding.EncodeToString([]byte(s)),
		base64.RawStdEncoding.EncodeToString([]byte(s)),
		url.QueryEscape(s),
		url.PathEscape(s),
	} {
		if got := m.Mask("before " + form + " after"); got != "before *** after" {
			t.Errorf("form %q not masked: %q", form, got)
		}
	}
	if got := m.Mask("abc is short"); got != "abc is short" {
		t.Errorf("short values must not be masked: %q", got)
	}
	pem := "-----BEGIN KEY-----\nMIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8w\n-----END KEY-----"
	if got := NewMasker(pem).Mask("line MIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8w"); strings.Contains(got, "MIIBVQ") {
		t.Errorf("multi-line secret line leaked: %q", got)
	}
	var nilMasker *Masker
	if nilMasker.Mask("x") != "x" {
		t.Error("nil masker")
	}
}

func TestMaskValues(t *testing.T) {
	env := map[string]Secret{"DATABASE_URL": "postgres://u:hunter22@db/x", "API_KEY": "k3y-value"}
	got := MaskValues(env, []string{"DATABASE_URL", "API_KEY", "NOT_IN_ENV"})
	want := []string{"hunter22", "k3y-value", "postgres://u:hunter22@db/x"}
	if strings.Join(got, ",") != strings.Join(want, ",") {
		t.Errorf("MaskValues = %v, want %v", got, want)
	}
}
