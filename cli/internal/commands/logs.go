package commands

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"strconv"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/sse"
)

func (a *App) logsCmd() *cobra.Command {
	var deployment, since, source string
	var follow bool
	cmd := &cobra.Command{
		Use:   "logs",
		Short: "Show recent log lines; -f follows them live",
		Long:  "Build and runtime lines of the linked environment (or --service). --deployment shows one deployment's log. With --json, every line is one JSON object (JSON Lines).",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			switch since {
			case "15m", "1h", "24h", "7d":
			default:
				return exitcode.Usagef("--since must be 15m, 1h, 24h or 7d")
			}
			switch source {
			case "all", "build", "runtime":
			default:
				return exitcode.Usagef("--source must be all, build or runtime")
			}
			s, err := a.session()
			if err != nil {
				return err
			}
			if deployment != "" {
				return a.deploymentLogs(ctx, s, deployment, follow)
			}
			t, err := a.resolve(ctx, s, false)
			if err != nil {
				return err
			}
			q := api.LogQuery{Source: source, Range: since}
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
			return s.Client.Stream(ctx, "/environments/"+url.PathEscape(t.Environment.ID)+"/logs/stream", vals, strconv.Itoa(meta.Cursor), func(ev sse.Event) error {
				if ev.Event != "lines" {
					return nil
				}
				var le linesEvent
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
	f.StringVar(&since, "since", "1h", "how far back: 15m, 1h, 24h or 7d")
	f.StringVar(&source, "source", "all", "all, build or runtime")
	return cmd
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
		var le linesEvent
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
	p := a.Printer
	if p.JSON {
		b, _ := json.Marshal(e)
		_, _ = fmt.Fprintln(p.Out, string(b))
		return
	}
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
	_, _ = fmt.Fprintf(p.Out, "%s %s %s\n", p.Dim(ts), p.Dim(fmt.Sprintf("%-12s", e.Service)), msg)
}
