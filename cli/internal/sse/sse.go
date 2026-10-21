// Package sse reads server-sent events (text/event-stream) as the launchroom
// API sends them for log follow (docs/16-api-and-cli.md §2.3).
package sse

import (
	"bufio"
	"io"
	"strings"
)

// Event is one dispatched event. Event defaults to "message"; comments
// (": ping") are not events.
type Event struct {
	ID    string
	Event string
	Data  string
}

// Reader parses events from a stream.
type Reader struct {
	s      *bufio.Scanner
	lastID string
}

// NewReader returns a reader over r. Lines may be up to 4 MiB (a log batch).
func NewReader(r io.Reader) *Reader {
	s := bufio.NewScanner(r)
	s.Buffer(make([]byte, 64*1024), 4<<20)
	return &Reader{s: s}
}

// LastID is the id of the last event that carried one (for Last-Event-ID).
func (r *Reader) LastID() string { return r.lastID }

// Next returns the next event, or io.EOF when the stream ended. A
// partially received event at EOF is dropped, as the spec requires.
func (r *Reader) Next() (Event, error) {
	var ev Event
	var data []string
	hasData := false
	for r.s.Scan() {
		line := strings.TrimSuffix(r.s.Text(), "\r")
		if line == "" {
			if !hasData {
				ev = Event{}
				continue
			}
			ev.Data = strings.Join(data, "\n")
			if ev.Event == "" {
				ev.Event = "message"
			}
			return ev, nil
		}
		if strings.HasPrefix(line, ":") {
			continue
		}
		field, value, found := strings.Cut(line, ":")
		if found {
			value = strings.TrimPrefix(value, " ")
		}
		switch field {
		case "event":
			ev.Event = value
		case "data":
			data = append(data, value)
			hasData = true
		case "id":
			if !strings.ContainsRune(value, 0) {
				ev.ID = value
				r.lastID = value
			}
		}
	}
	if err := r.s.Err(); err != nil {
		return Event{}, err
	}
	return Event{}, io.EOF
}
