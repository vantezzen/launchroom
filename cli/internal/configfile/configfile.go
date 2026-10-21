// Package configfile checks launchroom.json locally (docs/18 §7): JSON
// syntax with a line and column before anything is sent, and the excerpt
// printed under a problem. The schema and rules are checked by the
// dashboard, with the same code as pushes.
package configfile

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"
	"unicode/utf8"
)

// DefaultPath is where the file lives unless a project configures another.
const DefaultPath = "launchroom.json"

// MaxBytes is the size limit of the file (the dashboard refuses larger ones).
const MaxBytes = 256 * 1024

// SyntaxError is a launchroom.json that isn't valid JSON.
type SyntaxError struct {
	Line, Column int
	Message      string
}

func (e *SyntaxError) Error() string {
	return fmt.Sprintf("line %d, column %d: %s", e.Line, e.Column, e.Message)
}

// Check parses content as one JSON object and reports where it breaks.
func Check(content []byte) error {
	if len(content) > MaxBytes {
		return &SyntaxError{Line: 1, Column: 1, Message: "the file is larger than 256 KB"}
	}
	content = bytes.TrimPrefix(content, []byte("\xef\xbb\xbf"))
	if len(bytes.TrimSpace(content)) == 0 {
		return &SyntaxError{Line: 1, Column: 1, Message: "the file is empty"}
	}
	dec := json.NewDecoder(bytes.NewReader(content))
	var v any
	if err := dec.Decode(&v); err != nil {
		return syntaxError(content, err)
	}
	end := int(dec.InputOffset())
	if rest := bytes.TrimLeft(content[end:], " \t\r\n"); len(rest) > 0 {
		line, col := Position(content, len(content)-len(rest))
		return &SyntaxError{Line: line, Column: col, Message: "unexpected text after the end of the document"}
	}
	if _, ok := v.(map[string]any); !ok {
		return &SyntaxError{Line: 1, Column: 1, Message: "the file must be a JSON object { … }"}
	}
	return nil
}

func syntaxError(content []byte, err error) error {
	var se *json.SyntaxError
	if errors.As(err, &se) {
		offset := int(se.Offset) - 1
		if offset < 0 {
			offset = 0
		}
		line, col := Position(content, offset)
		msg := strings.TrimPrefix(se.Error(), "invalid character ")
		switch {
		case bytes.HasPrefix(content[min(offset, len(content)):], []byte("//")), bytes.HasPrefix(content[min(offset, len(content)):], []byte("/*")):
			msg = "comments aren't allowed in JSON"
		case strings.Contains(se.Error(), "looking for beginning of object key string") && strings.HasPrefix(msg, "'}'"):
			msg = "trailing comma before }: JSON doesn't allow one"
		case strings.Contains(se.Error(), "looking for beginning of value") && strings.HasPrefix(msg, "']'"):
			msg = "trailing comma before ]: JSON doesn't allow one"
		default:
			msg = se.Error()
		}
		return &SyntaxError{Line: line, Column: col, Message: msg}
	}
	if errors.Is(err, io.ErrUnexpectedEOF) {
		line, col := Position(content, len(content))
		return &SyntaxError{Line: line, Column: col, Message: "the file ends too early (a } or ] is missing)"}
	}
	return &SyntaxError{Line: 1, Column: 1, Message: err.Error()}
}

// Position turns a byte offset into a 1-based line and column (columns in
// characters).
func Position(content []byte, offset int) (int, int) {
	if offset > len(content) {
		offset = len(content)
	}
	before := content[:offset]
	line := bytes.Count(before, []byte("\n")) + 1
	start := bytes.LastIndexByte(before, '\n') + 1
	return line, utf8.RuneCount(before[start:]) + 1
}

// Excerpt renders lines around line (1-based) with a caret under column.
func Excerpt(lines []string, first, line, column int) string {
	var b strings.Builder
	for i, text := range lines {
		n := first + i
		marker := "  "
		if n == line {
			marker = "> "
		}
		fmt.Fprintf(&b, "%s%4d │ %s\n", marker, n, text)
		if n == line && column > 0 {
			fmt.Fprintf(&b, "  %4s │ %s^\n", "", strings.Repeat(" ", column-1))
		}
	}
	return b.String()
}

// Around returns up to two lines before and after line of content, and the
// number of the first.
func Around(content []byte, line int) ([]string, int) {
	all := strings.Split(strings.ReplaceAll(string(content), "\r\n", "\n"), "\n")
	from := max(1, line-2)
	to := min(len(all), line+2)
	if from > to {
		return nil, from
	}
	return all[from-1 : to], from
}
