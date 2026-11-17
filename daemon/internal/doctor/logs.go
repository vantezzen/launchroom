package doctor

import (
	"encoding/json"
	"strings"
)

// logPattern maps a known log line to the plain-language cause.
type logPattern struct {
	contains []string
	says     string
}

var postgresPatterns = []logPattern{
	{[]string{"No space left on device", "could not write", "could not extend file"}, "The database couldn't write to disk: the disk is full."},
	{[]string{"PANIC:"}, "The database stopped with a serious error (PANIC). Its log has the details: ./launchroom logs postgres"},
	{[]string{"invalid page in block", "could not read block", "invalid record length", "invalid checkpoint record"}, "The database's files look damaged."},
	{[]string{"could not open file"}, "The database is missing some of its files."},
	{[]string{"database files are incompatible with server"}, "The database files were written by another Postgres major version than the one that runs now."},
}

var valkeyPatterns = []logPattern{
	{[]string{"Bad file format reading the append only file", "Unrecoverable error reading the append only file", "AOF is not valid", "Unexpected end of file reading the append only file"},
		"Valkey can't load its append-only file: it was damaged, usually by a full disk or a hard stop."},
	{[]string{"No space left on device"}, "Valkey couldn't write to disk: the disk is full."},
}

// matchLog returns the cause of the last known pattern in logs, or "".
func matchLog(logs string, patterns []logPattern) string {
	found, at := "", -1
	for _, p := range patterns {
		for _, c := range p.contains {
			if i := strings.LastIndex(logs, c); i > at {
				found, at = p.says, i
			}
		}
	}
	return found
}

// errorLines returns the messages of the last n level=ERROR lines of a
// JSON (slog) log.
func errorLines(logs string, n int) []string {
	var out []string
	for l := range strings.SplitSeq(logs, "\n") {
		i := strings.IndexByte(l, '{')
		if i < 0 {
			continue
		}
		var e struct {
			Level string `json:"level"`
			Msg   string `json:"msg"`
			Err   string `json:"err"`
		}
		if json.Unmarshal([]byte(l[i:]), &e) != nil || e.Level != "ERROR" {
			continue
		}
		m := e.Msg
		if e.Err != "" {
			m += ": " + e.Err
		}
		out = append(out, clip(redactLine(m), 200))
	}
	if len(out) > n {
		out = out[len(out)-n:]
	}
	return out
}
