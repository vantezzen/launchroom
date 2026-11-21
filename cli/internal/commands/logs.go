package commands

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/url"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/sse"
)

func (a *App) logsCmd() *cobra.Command {
	var deployment, since, source, grep, from, to, level string
	var follow bool
	cmd := &cobra.Command{
		Use:   "logs",
		Short: "Show recent log lines; -f follows them live",
		Long: "Build and runtime lines of the linked environment (or --service). --deployment shows one deployment's log. With --json, every line is one JSON object (JSON Lines).\n\n" +
			"  launchroom logs -q timeout --level error      errors that mention timeout in the last hour\n" +
			"  launchroom logs --from 2h --to 1h             an absolute window (also RFC 3339 or 15:04 today)\n" +
			"  launchroom logs -q 'service:api -level:debug'  the log explorer's search syntax",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			if !slices.Contains(api.LogRangeValues, since) {
				return exitcode.Usagef("--since must be %s", oneOf(api.LogRangeValues))
			}
			if !slices.Contains(api.LogSourceValues, source) {
				return exitcode.Usagef("--source must be %s", oneOf(api.LogSourceValues))
			}
			q := api.LogQuery{Source: source, Range: since, Q: grep}
			if level != "" {
				if !slices.Contains(api.LogLineLevelValues, level) {
					return exitcode.Usagef("--level must be %s", oneOf(api.LogLineLevelValues))
				}
				q.Q = strings.TrimSpace(q.Q + " level:" + level)
			}
			if from != "" || to != "" {
				if follow {
					return exitcode.Usagef("--from and --to don't combine with --follow")
				}
				var err error
				if q.From, q.To, err = logWindow(from, to, time.Now()); err != nil {
					return err
				}
			}
			s, err := a.session()
			if err != nil {
				return err
			}
			if deployment != "" {
				if q.Q != "" || q.From > 0 {
					return exitcode.Usagef("--deployment shows the whole log: -q, --level, --from and --to apply to the environment's logs")
				}
				return a.deploymentLogs(ctx, s, deployment, follow)
			}
			t, err := a.resolve(ctx, s, false)
			if err != nil {
				return err
			}
			if t.Service != nil {
				q.Service = t.Service.ID
			}
			entries, meta, err := s.Client.EnvironmentLogs(ctx, t.Environment.ID, q)
			if err != nil {
				return err
			}
			for _, e := range entries {
				a.printEntry(e)
			}
			if meta.Truncated && !a.Printer.JSON {
				a.Printer.Info(a.Printer.Dim("(older lines in this range were left out; narrow it with --since or --service)"))
			}
			if !follow {
				return nil
			}
			vals := url.Values{"source": {source}}
			if q.Service != "" {
				vals.Set("service", q.Service)
			}
			if q.Q != "" {
				vals.Set("q", q.Q)
			}
			return s.Client.Stream(ctx, "/environments/"+url.PathEscape(t.Environment.ID)+"/logs/stream", vals, strconv.Itoa(meta.Cursor), func(ev sse.Event) error {
				if ev.Event != "lines" {
					return nil
				}
				var le api.EnvironmentLinesEvent
				if json.Unmarshal([]byte(ev.Data), &le) == nil {
					for _, e := range le.Entries {
						a.printEntry(e)
					}
				}
				return nil
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&deployment, "deployment", "", "one deployment's log (dpl_…)")
	f.BoolVarP(&follow, "follow", "f", false, "keep following new lines")
	f.StringVar(&since, "since", api.LogRange1h, "how far back: "+oneOf(api.LogRangeValues))
	f.StringVar(&source, "source", api.LogSourceAll, oneOf(api.LogSourceValues))
	f.StringVarP(&grep, "grep", "q", "", "only lines with this text (the log explorer's search: `level:error \"timeout\"`)")
	f.StringVar(&level, "level", "", "only lines of this level: "+oneOf(api.LogLineLevelValues))
	f.StringVar(&from, "from", "", "start of an absolute window: RFC 3339, 15:04 (today) or 30m/2h/3d ago")
	f.StringVar(&to, "to", "", "end of the window (default now), like --from")
	return cmd
}

// logWindow turns --from/--to into epoch seconds; --to alone is a mistake.
func logWindow(from, to string, now time.Time) (int64, int64, error) {
	if from == "" {
		return 0, 0, exitcode.Usagef("--to needs --from")
	}
	start, err := parseWhen(from, now)
	if err != nil {
		return 0, 0, exitcode.Usagef("--from: %v", err)
	}
	end := now
	if to != "" {
		if end, err = parseWhen(to, now); err != nil {
			return 0, 0, exitcode.Usagef("--to: %v", err)
		}
	}
	if !end.After(start) {
		return 0, 0, exitcode.Usagef("--to must be after --from")
	}
	return start.Unix(), end.Unix(), nil
}

// parseWhen reads a point in time: RFC 3339, "15:04" (today, local time) or
// a duration ago ("30m", "2h", "3d").
func parseWhen(s string, now time.Time) (time.Time, error) {
	s = strings.TrimSpace(s)
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		return t, nil
	}
	if t, err := time.ParseInLocation("15:04", s, now.Location()); err == nil {
		return time.Date(now.Year(), now.Month(), now.Day(), t.Hour(), t.Minute(), 0, 0, now.Location()), nil
	}
	if days, ok := strings.CutSuffix(s, "d"); ok {
		if n, err := strconv.Atoi(days); err == nil && n >= 0 {
			return now.Add(-time.Duration(n) * 24 * time.Hour), nil
		}
	}
	if d, err := time.ParseDuration(s); err == nil && d >= 0 {
		return now.Add(-d), nil
	}
	return time.Time{}, fmt.Errorf("%q is not a time (RFC 3339, 15:04, or 30m/2h/3d ago)", s)
}

// oneOf lists values for a message: "a, b or c".
func oneOf(values []string) string {
	if len(values) < 2 {
		return strings.Join(values, "")
	}
	return strings.Join(values[:len(values)-1], ", ") + " or " + values[len(values)-1]
}

func (a *App) deploymentLogs(ctx context.Context, s *session, id string, follow bool) error {
	after := 0
	for {
		lines, meta, err := s.Client.DeploymentLogs(ctx, id, after)
		if err != nil {
			return err
		}
		for _, l := range lines {
			a.printDeploymentLine(l)
		}
		after = meta.Cursor
		if !meta.HasMore {
			if !follow || meta.Finished {
				return nil
			}
			break
		}
	}
	return s.Client.Stream(ctx, "/deployments/"+url.PathEscape(id)+"/logs/stream", nil, strconv.Itoa(after), func(ev sse.Event) error {
		if ev.Event != "lines" {
			return nil
		}
		var le api.DeploymentLinesEvent
		if json.Unmarshal([]byte(ev.Data), &le) == nil {
			for _, l := range le.Lines {
				a.printDeploymentLine(l)
			}
		}
		return nil
	})
}

// printDeploymentLine writes one line (JSON Lines in --json mode).
func (a *App) printDeploymentLine(l api.LogLine) {
	if a.Printer.JSON {
		b, _ := json.Marshal(l)
		_, _ = fmt.Fprintln(a.Printer.Out, string(b))
		return
	}
	a.printLogLine(l)
}

func (a *App) printEntry(e api.LogEntry) {
	if a.Printer.JSON {
		b, _ := json.Marshal(e)
		_, _ = fmt.Fprintln(a.Printer.Out, string(b))
		return
	}
	a.printEntryTo(a.Printer.Out, e)
}

// printEntryTo writes one environment log line for humans.
func (a *App) printEntryTo(w io.Writer, e api.LogEntry) {
	p := a.Printer
	msg := e.Message
	switch e.Level {
	case "error":
		msg = p.Bad(msg)
	case "warning":
		msg = p.Warn(msg)
	}
	ts := e.Time
	if len(ts) >= 19 {
		ts = ts[11:19]
	}
	_, _ = fmt.Fprintf(w, "%s %s %s\n", p.Dim(ts), p.Dim(fmt.Sprintf("%-12s", e.Service)), msg)
}
