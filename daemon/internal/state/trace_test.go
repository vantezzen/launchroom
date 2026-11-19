package state

import (
	"encoding/base64"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

func TestTraceIngressSecret(t *testing.T) {
	key := base64.StdEncoding.EncodeToString([]byte(strings.Repeat("k", TraceKeyBytes)))
	ok := map[string]secret.Secret{"key": secret.Secret(key), "keyId": "7"}
	s := &IngressSecret{ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Purpose: IngressSecretTrace, Values: ok}
	if err := s.Validate(); err != nil {
		t.Fatalf("valid trace secret refused: %v", err)
	}
	tk, err := TraceKeyOf(ok)
	if err != nil || tk.ID != 7 || tk.Key.Reveal() != strings.Repeat("k", TraceKeyBytes) {
		t.Fatalf("TraceKeyOf = %d, %v", tk.ID, err)
	}

	short := base64.StdEncoding.EncodeToString([]byte(strings.Repeat("k", 16)))
	for name, v := range map[string]map[string]secret.Secret{
		"no key id":     {"key": secret.Secret(key)},
		"short key":     {"key": secret.Secret(short), "keyId": "7"},
		"url base64":    {"key": secret.Secret(strings.ReplaceAll(base64.StdEncoding.EncodeToString(make([]byte, 32)), "A", "-")), "keyId": "7"},
		"key id zero":   {"key": secret.Secret(key), "keyId": "0"},
		"leading zero":  {"key": secret.Secret(key), "keyId": "07"},
		"key id 2^32":   {"key": secret.Secret(key), "keyId": "4294967296"},
		"negative":      {"key": secret.Secret(key), "keyId": "-1"},
		"unknown value": {"key": secret.Secret(key), "keyId": "7", "extra": "x"},
	} {
		t.Run(name, func(t *testing.T) {
			s := &IngressSecret{ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Purpose: IngressSecretTrace, Values: v}
			err := s.Validate()
			if err == nil {
				t.Fatal("Validate accepted")
			}
			if strings.Contains(err.Error(), key) {
				t.Fatal("error leaks the key")
			}
			if _, err := TraceKeyOf(v); err == nil {
				t.Fatal("TraceKeyOf accepted")
			}
		})
	}
	if _, err := ParseTraceKeyID("4294967295"); err != nil {
		t.Fatalf("max key id refused: %v", err)
	}
}

func TestTraceRouteFields(t *testing.T) {
	var s NodeState
	if err := decodeFixture(t, "node-state.trace.json", &s); err != nil {
		t.Fatal(err)
	}
	if s.Ingress == nil || s.Ingress.Trace == nil || s.Routes[0].Service == "" || s.Routes[0].Upstreams[1].Server == "" {
		t.Fatal("fixture lost the trace fields")
	}
	vocab := ProtocolVocabulary()
	for _, f := range []string{"NodeState.ingress.trace", "NodeState.routes[].service", "NodeState.routes[].upstreams[].server"} {
		if vocab.FieldFeatures[f] != FeatureRequestTrace {
			t.Errorf("fieldFeatures[%s] = %q", f, vocab.FieldFeatures[f])
		}
	}
}
