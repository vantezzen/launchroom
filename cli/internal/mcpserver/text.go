package mcpserver

import (
	"fmt"
	"regexp"
	"strings"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/cli/internal/api"
)

// Output bounds (docs/16 §7.4).
const (
	maxText       = 48 << 10 // one text block
	maxLineBytes  = 2 << 10  // one log or output line
	maxOutputText = 32 << 10 // command output in an answer
)

// untrustedTag matches the block's own tags inside data, any case, so data
// can't end (or fake) the block it is in.
var untrustedTag = regexp.MustCompile(`(?i)<(/?)(\s*)untrusted-data`)

// untrusted puts text an app, its users or a repository wrote into a
// labelled block: the model reads it as data, never as instructions.
func untrusted(source, body string) string {
	body = untrustedTag.ReplaceAllString(body, "&lt;${1}${2}untrusted-data")
	return fmt.Sprintf("<untrusted-data source=%q>\n%s\n</untrusted-data>", source, strings.TrimRight(body, "\n"))
}

// clip shortens one line to maxLineBytes on a rune boundary.
func clip(s string) string {
	if len(s) <= maxLineBytes {
		return s
	}
	cut := maxLineBytes
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut] + " … (line cut at 2 KiB)"
}

// capText keeps a text block under maxText, saying what was left out.
func capText(s string) string {
	if len(s) <= maxText {
		return s
	}
	cut := maxText
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut] + "\n… (answer cut at 48 KiB: narrow the request, e.g. a smaller limit or a search)"
}

func deref(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func firstLine(s string) string {
	line, _, _ := strings.Cut(strings.TrimSpace(s), "\n")
	return line
}

func plural(n int, one, many string) string {
	if n == 1 {
		return fmt.Sprintf("%d %s", n, one)
	}
	return fmt.Sprintf("%d %s", n, many)
}

// deploymentLine is one deployment in a sentence: id, status, commit.
func deploymentLine(d api.Deployment) string {
	var b strings.Builder
	fmt.Fprintf(&b, "%s %s", d.ID, words(d.Status))
	if d.ServiceName != "" {
		fmt.Fprintf(&b, " (%s)", d.ServiceName)
	}
	if d.Commit != nil {
		fmt.Fprintf(&b, ", commit %s", d.Commit.ShortSha)
		if d.Commit.Ref != nil {
			fmt.Fprintf(&b, " on %s", *d.Commit.Ref)
		}
	}
	fmt.Fprintf(&b, ", %s", d.Trigger)
	switch {
	case d.FinishedAt != nil:
		fmt.Fprintf(&b, ", finished %s", *d.FinishedAt)
	case d.StartedAt != nil:
		fmt.Fprintf(&b, ", started %s", *d.StartedAt)
	default:
		fmt.Fprintf(&b, ", queued %s", d.QueuedAt)
	}
	if d.Failure != nil && d.Failure.Title != nil {
		// The message can quote build output, so only the title (launchroom's
		// own words) goes into plain text.
		fmt.Fprintf(&b, ". %s", *d.Failure.Title)
	}
	if d.WaitingForChecks() {
		b.WriteString(". Waiting for the commit's CI checks")
	}
	return b.String()
}

// words turns a status value into words ("health_checking" → "health checking").
func words(s string) string { return strings.ReplaceAll(s, "_", " ") }

// commitMessages are the commit messages of ds, for one untrusted block.
func commitMessages(ds ...api.Deployment) string {
	var b strings.Builder
	for _, d := range ds {
		if d.Commit != nil && d.Commit.Message != nil {
			fmt.Fprintf(&b, "%s: %s\n", d.Commit.ShortSha, clip(firstLine(*d.Commit.Message)))
		}
	}
	return b.String()
}
