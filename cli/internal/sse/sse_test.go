package sse

import (
	"errors"
	"io"
	"strings"
	"testing"
)

func TestReader(t *testing.T) {
	stream := ": ping\n\n" +
		"event: lines\r\nid: 42\r\ndata: {\"a\":1}\r\n\r\n" +
		"data: first\ndata: second\n\n" +
		"event: end\ndata\n\n" +
		"event: partial\ndata: dropped"
	r := NewReader(strings.NewReader(stream))

	ev, err := r.Next()
	if err != nil || ev.Event != "lines" || ev.ID != "42" || ev.Data != `{"a":1}` {
		t.Fatalf("first event = %+v, %v", ev, err)
	}
	ev, err = r.Next()
	if err != nil || ev.Event != "message" || ev.Data != "first\nsecond" || ev.ID != "" {
		t.Fatalf("second event = %+v, %v", ev, err)
	}
	if r.LastID() != "42" {
		t.Fatalf("LastID = %q", r.LastID())
	}
	ev, err = r.Next()
	if err != nil || ev.Event != "end" || ev.Data != "" {
		t.Fatalf("end event = %+v, %v", ev, err)
	}
	if _, err := r.Next(); !errors.Is(err, io.EOF) {
		t.Fatalf("a partial event at EOF must be dropped, got %v", err)
	}
}
