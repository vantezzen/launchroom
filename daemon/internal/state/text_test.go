package state

import (
	"reflect"
	"strconv"
	"strings"
	"testing"
	"unicode/utf8"
)

func TestTextLimitsMatchSchemaTags(t *testing.T) {
	for _, c := range []struct {
		typ   reflect.Type
		field string
		limit int
	}{
		{reflect.TypeFor[TaskCompletion](), "Error", MaxMessageLen},
		{reflect.TypeFor[WorkloadStatus](), "Message", MaxMessageLen},
		{reflect.TypeFor[IngressStatus](), "Message", MaxMessageLen},
		{reflect.TypeFor[MeshStatus](), "Message", MaxStatusLen},
		{reflect.TypeFor[WalArchiveStatus](), "Message", MaxStatusLen},
		{reflect.TypeFor[ProbeStatus](), "LastError", MaxStatusLen},
		{reflect.TypeFor[VerifyCheck](), "Detail", MaxDetailLen},
		{reflect.TypeFor[TaskHeartbeat](), "Progress", MaxProgressLen},
		{reflect.TypeFor[LogLine](), "Step", MaxLogStepLen},
		{reflect.TypeFor[TaskFailure](), "Step", MaxFailureStepLen},
	} {
		f, ok := c.typ.FieldByName(c.field)
		if !ok {
			t.Fatalf("%s has no field %s", c.typ, c.field)
		}
		if want := "maxLength=" + strconv.Itoa(c.limit); !strings.Contains(f.Tag.Get("jsonschema"), want) {
			t.Errorf("%s.%s: tag %q does not carry %s", c.typ, c.field, f.Tag.Get("jsonschema"), want)
		}
	}
}

func TestClip(t *testing.T) {
	if got := Clip("héllo", 2); got != "h" {
		t.Errorf("Clip cut inside a rune: %q", got)
	}
	if got := Clip("ok\xff", 10); !utf8.ValidString(got) {
		t.Errorf("Clip kept invalid UTF-8: %q", got)
	}
	if got := ClipLine("a\nb\x1bc", 10); got != "abc" {
		t.Errorf("ClipLine = %q", got)
	}
}

// A clip that lands inside a multi-byte rune must still encode: json/v2
// refuses invalid UTF-8, and a completion that can't be encoded is never
// delivered.
func TestClipCutInsideRuneEncodes(t *testing.T) {
	long := strings.Repeat("a", MaxMessageLen-1) + "😀 tail"
	step := strings.Repeat("b", MaxFailureStepLen-2) + "é😀"
	c := TaskCompletion{Attempt: 1, Outcome: OutcomeFailed, Error: Clip(long, MaxMessageLen), Failure: &TaskFailure{Class: FailureBuild, Step: Clip(step, MaxFailureStepLen)}}
	if _, err := Encode(c); err != nil {
		t.Fatalf("clipped completion does not encode: %v", err)
	}
	if got := Shorten(strings.Repeat("x", 9)+"😀", 10); got != strings.Repeat("x", 9)+"…" {
		t.Errorf("Shorten = %q", got)
	}
	if got := Shorten("ok\xff", 10); !utf8.ValidString(got) {
		t.Errorf("Shorten kept invalid UTF-8: %q", got)
	}
}
