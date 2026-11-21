package commands

import (
	"context"
	"fmt"
	"io"
	"regexp"
	"strings"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// rayHeader matches the ray's header line in any text (a whole `curl -i`
// output), the header name before the ray in older edges too.
var rayHeader = regexp.MustCompile(`(?im)^\s*(?:x-launchroom-ray|launchroom-trace)\s*:\s*(\S+)`)

// rayToken is a bare value (`lr1.…`, or a newer format's prefix).
var rayToken = regexp.MustCompile(`\blr\d+\.[A-Za-z0-9_-]+`)

// maxRayInput bounds what is read from stdin (a `curl -i` of a big page).
const maxRayInput = 1 << 20

// findRay returns the ray in text: the header's value, else a bare
// `lr<n>.` token, else the trimmed text (the dashboard decides).
func findRay(text string) string {
	if m := rayHeader.FindStringSubmatch(text); m != nil {
		return strings.Trim(m[1], `"'`)
	}
	if m := rayToken.FindString(text); m != "" {
		return m
	}
	return strings.TrimSpace(text)
}

func (a *App) rayCmd() *cobra.Command {
	var noLogs bool
	cmd := &cobra.Command{
		Use:   "ray [VALUE | -]",
		Short: "Show what happened to a request, from its X-Launchroom-Ray header",
		Long: "Every response through launchroom carries an X-Launchroom-Ray header. Paste its value, or pipe in any text that has it:\n\n" +
			"  launchroom ray lr1.AbC…\n" +
			"  curl -si https://shop.example.com/checkout | launchroom ray\n\n" +
			"Prints the request's status, where its time went, the firewall's decision, the servers on its path and the\n" +
			"service's log lines around it. A ray that isn't from one of your team's services exits with code 6.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			input := ""
			if len(args) == 1 && args[0] != "-" {
				input = args[0]
			} else {
				b, err := io.ReadAll(io.LimitReader(a.Stdin, maxRayInput))
				if err != nil {
					return err
				}
				input = string(b)
			}
			value := findRay(input)
			if value == "" {
				return exitcode.Usagef("no ray given: pass the X-Launchroom-Ray value or pipe in a response that has it")
			}
			s, err := a.session()
			if err != nil {
				return err
			}
			tr, err := s.Client.DecodeTrace(ctx, value)
			if err != nil {
				return err
			}
			var logs []api.LogEntry
			if !noLogs {
				logs = a.rayLogs(ctx, s, tr)
			}
			out := map[string]any{"trace": tr, "logs": logs}
			if noLogs {
				out = map[string]any{"trace": tr}
			}
			return a.Printer.Result(out, func(w io.Writer) {
				a.printTrace(w, tr)
				if noLogs || tr.Service == nil {
					return
				}
				_, _ = fmt.Fprintln(w)
				if len(logs) == 0 {
					_, _ = fmt.Fprintln(w, a.Printer.Dim("No log lines mention this request."))
					return
				}
				_, _ = fmt.Fprintln(w, a.Printer.Dim("Log lines that mention it"))
				for _, e := range logs {
					a.printEntryTo(w, e)
				}
			})
		},
	}
	cmd.Flags().BoolVar(&noLogs, "no-logs", false, "leave out the service's log lines")
	return cmd
}

// rayLogs are the service's lines that mention the request id, in the
// minute around it. Errors leave them out: the trace is the answer.
func (a *App) rayLogs(ctx context.Context, s *session, tr api.Trace) []api.LogEntry {
	if tr.Service == nil || tr.LogsFrom == nil || tr.LogsTo == nil {
		return nil
	}
	entries, _, err := s.Client.EnvironmentLogs(ctx, tr.Service.EnvironmentID, api.LogQuery{
		Service: tr.Service.ID, Q: `"` + tr.RequestID + `"`, From: int64(*tr.LogsFrom), To: int64(*tr.LogsTo),
	})
	if err != nil {
		return nil
	}
	return entries
}

// endsAtWords says where the request ended (TraceStop).
func endsAtWords(stop string) string {
	switch stop {
	case api.TraceStopEdge:
		return "at the edge"
	case api.TraceStopFirewall:
		return "at the firewall"
	case api.TraceStopAppServer:
		return "at the app server"
	case api.TraceStopService:
		return "at the service"
	default:
		return strings.ReplaceAll(stop, "_", " ")
	}
}

func millis(us *int) string {
	if us == nil {
		return "–"
	}
	if *us < 1000 {
		return fmt.Sprintf("%d µs", *us)
	}
	return fmt.Sprintf("%.1f ms", float64(*us)/1000)
}

// printTrace writes the decoded ray as aligned label/value rows.
func (a *App) printTrace(w io.Writer, tr api.Trace) {
	p := a.Printer
	row := func(label, value string) {
		_, _ = fmt.Fprintf(w, "  %s %s\n", p.Dim(fmt.Sprintf("%-9s", label)), value)
	}
	status := fmt.Sprint(tr.Status)
	switch {
	case tr.Status >= 500:
		status = p.Bad("✕ " + status)
	case tr.Status >= 400:
		status = p.Warn("⚠ " + status)
	default:
		status = p.Good("● " + status)
	}
	if tr.UpstreamStatus != nil {
		status += p.Dim(fmt.Sprintf(" (the app answered %d)", *tr.UpstreamStatus))
	}
	request := strings.TrimSpace(strings.Join([]string{deref(tr.Method), deref(tr.Path), p.Dim(deref(tr.Protocol))}, " "))
	_, _ = fmt.Fprintf(w, "%s  %s\n", status, request)
	_, _ = fmt.Fprintf(w, "  %s\n\n", tr.Summary)
	if tr.Host != nil {
		row("Host", *tr.Host)
	}
	if tr.Service != nil {
		row("Service", fmt.Sprintf("%s %s %s", tr.Service.ProjectName, p.Dim("/ "+tr.Service.EnvironmentName+" /"), tr.Service.Name))
	} else if tr.Dashboard {
		row("Service", "the launchroom dashboard")
	}
	timing := "edge " + millis(tr.EdgeTimeUs)
	if tr.UpstreamTimeUs != nil {
		timing += p.Dim("  of which the app ") + millis(tr.UpstreamTimeUs)
	}
	if tr.Attempts != nil && *tr.Attempts > 1 {
		timing += p.Dim(fmt.Sprintf("  (%d attempts)", *tr.Attempts))
	}
	row("Time", timing)
	if fw := tr.Firewall; fw != nil {
		row("Firewall", fmt.Sprintf("%s %s", fw.Outcome, p.Dim(fmt.Sprintf("(%s, score %d)", fw.Mode, fw.Score))))
		for _, r := range fw.Rules {
			row("", fmt.Sprintf("%s %s", p.Dim(fmt.Sprint(r.ID)), r.Message))
		}
	}
	path := tr.Edge.Name
	switch {
	case tr.SameServer:
		path += p.Dim(" (edge and app on one server)")
	case tr.UpstreamServer != nil:
		path += " → " + tr.UpstreamServer.Name
	}
	row("Path", path)
	ended := "Ended " + endsAtWords(tr.EndsAt)
	if tr.Failure != nil {
		ended += p.Dim(" (" + strings.ReplaceAll(*tr.Failure, "_", " ") + ")")
	}
	row("", ended)
	row("Request", p.Dim(tr.RequestID+"  "+tr.ReceivedAt))
	row("More", tr.DashboardURL)
}

func deref(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}
