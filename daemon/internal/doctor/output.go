package doctor

import (
	"fmt"
	"io"
	"strings"
)

var statusWord = map[Status]string{
	StatusOK:   "ok",
	StatusWarn: "warning",
	StatusFail: "problem",
	StatusSkip: "skipped",
}

// WriteText prints the report for people: one line per check, grouped,
// then what needs attention with the command that fixes it.
func WriteText(w io.Writer, rep Report, command string) {
	where := "control-plane server"
	if rep.Mode == ModeServer {
		where = "server"
	}
	_, _ = fmt.Fprintf(w, "launchroom doctor %s (%s)\n", rep.Version, where)
	group := ""
	for _, r := range rep.Results {
		if r.Group != group {
			group = r.Group
			_, _ = fmt.Fprintf(w, "\n%s\n", group)
		}
		_, _ = fmt.Fprintf(w, "  %-8s %s: %s\n", statusWord[r.Status], r.Title, r.Summary)
		if r.Status == StatusFail || r.Status == StatusWarn {
			for _, d := range r.Detail {
				_, _ = fmt.Fprintf(w, "  %-8s %s\n", "", d)
			}
		}
	}
	var attention []Result
	for _, r := range rep.Results {
		if r.Status == StatusFail {
			attention = append(attention, r)
		}
	}
	for _, r := range rep.Results {
		if r.Status == StatusWarn && r.Fix != nil {
			attention = append(attention, r)
		}
	}
	if len(attention) == 0 {
		_, _ = fmt.Fprintln(w, "\nNothing needs attention.")
		return
	}
	_, _ = fmt.Fprintln(w, "\nWhat needs attention")
	for i, r := range attention {
		_, _ = fmt.Fprintf(w, "  %d. %s: %s\n", i+1, r.Title, r.Summary)
		if r.Fix != nil {
			id := string(r.Fix.ID)
			if r.Fix.Target != "" {
				id += ":" + r.Fix.Target
			}
			_, _ = fmt.Fprintf(w, "     %s\n     Fix: %s --fix %s\n", r.Fix.Does, command, id)
		}
	}
	if rep.Failed() {
		_, _ = fmt.Fprintf(w, "\nTo share the details, run %s --bundle and look through the file before you send it.\n", strings.TrimSpace(command))
	}
}
