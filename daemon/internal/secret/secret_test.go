package secret

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"
	"testing"
)

func TestSecretNeverLeaks(t *testing.T) {
	const plain = "hunter2-very-secret"
	s := Secret(plain)

	outputs := map[string]string{
		"%s":  fmt.Sprintf("%s", s),
		"%v":  fmt.Sprintf("%v", s),
		"%+v": fmt.Sprintf("%+v", s),
		"%#v": fmt.Sprintf("%#v", s),
		"%q":  fmt.Sprintf("%q", s),
		"%x":  fmt.Sprintf("%x", s),
		"struct": fmt.Sprintf("%+v", struct {
			Token Secret
		}{s}),
	}

	b, err := json.Marshal(struct{ Token Secret }{s})
	if err != nil {
		t.Fatal(err)
	}
	outputs["json"] = string(b)

	var buf bytes.Buffer
	slog.New(slog.NewJSONHandler(&buf, nil)).Info("msg", "token", s, slog.Any("any", s))
	outputs["slog"] = buf.String()

	for name, out := range outputs {
		if strings.Contains(out, plain) {
			t.Errorf("%s leaked the secret: %s", name, out)
		}
		if !strings.Contains(out, Redacted) {
			t.Errorf("%s did not render the redaction marker: %s", name, out)
		}
	}

	if s.Reveal() != plain {
		t.Fatalf("Reveal() = %q", s.Reveal())
	}
	if s.IsZero() || !Secret("").IsZero() {
		t.Fatal("IsZero mismatch")
	}
}
