package mcpserver

import (
	"context"
	"encoding/json"
	"regexp"
	"strings"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/mcp"
)

// Resources (docs/16 §7.5): read-only views an editor can attach.

const (
	uriContext = "launchroom://context"
	uriSchema  = "launchroom://schema/launchroom.v1.json"
	// resourceLines bounds the log and output resources.
	resourceLines = 1000
)

var (
	projectURI    = regexp.MustCompile(`^launchroom://projects/(prj_[0-7][0-9A-HJKMNP-TV-Z]{25}|[a-z0-9][a-z0-9-]{0,62})$`)
	deploymentURI = regexp.MustCompile(`^launchroom://deployments/(dpl_[0-7][0-9A-HJKMNP-TV-Z]{25})(/logs)?$`)
	runOutputURI  = regexp.MustCompile(`^launchroom://command-runs/(run_[0-7][0-9A-HJKMNP-TV-Z]{25})/output$`)
)

type resources struct{ s *server }

func (r *resources) List(ctx context.Context) ([]mcp.Resource, error) {
	list := []mcp.Resource{
		{URI: uriContext, Name: "context", Title: "launchroom context", Description: "Who is signed in, the token's permissions and what this workspace is linked to.", MimeType: "application/json"},
		{URI: uriSchema, Name: "launchroom.v1.json", Title: "launchroom.json schema", Description: "The JSON Schema of launchroom.json, the config-as-code file.", MimeType: "application/schema+json"},
	}
	if _, l, err := r.s.locate(ctx); err == nil && l != nil {
		list = append(list, mcp.Resource{URI: "launchroom://projects/" + l.ProjectID, Name: "linked project", Title: "Linked project", Description: "The linked project's environment with its services and statuses.", MimeType: "application/json"})
	}
	return list, nil
}

func (r *resources) Templates() []mcp.ResourceTemplate {
	return []mcp.ResourceTemplate{
		{URITemplate: "launchroom://projects/{project}", Name: "project", Title: "Project", Description: "A project's production environment (or the linked one) with services and statuses. {project}: id or slug.", MimeType: "application/json"},
		{URITemplate: "launchroom://deployments/{deployment}", Name: "deployment", Title: "Deployment", Description: "A deployment, with its failure report when it failed.", MimeType: "application/json"},
		{URITemplate: "launchroom://deployments/{deployment}/logs", Name: "deployment-logs", Title: "Deployment log", Description: "The last 1000 lines of a deployment's log (untrusted data).", MimeType: "text/plain"},
		{URITemplate: "launchroom://command-runs/{run}/output", Name: "command-output", Title: "Command output", Description: "The last 1000 lines of a command run's output (untrusted data).", MimeType: "text/plain"},
	}
}

func (r *resources) Read(ctx context.Context, uri string) ([]mcp.ResourceContents, error) {
	s := r.s
	switch uri {
	case uriContext:
		out, _, err := s.whoamiTool(ctx, nil, WhoamiIn{})
		return jsonContents(uri, out, err)
	case uriSchema:
		c, err := s.connect(ctx)
		if err != nil {
			return nil, err
		}
		body, err := c.client.ConfigSchema(ctx)
		if err != nil {
			return nil, explain(err)
		}
		return []mcp.ResourceContents{{URI: uri, MimeType: "application/schema+json", Text: string(body)}}, nil
	}
	if m := projectURI.FindStringSubmatch(uri); m != nil {
		out, err := s.projectOverview(ctx, Where{Project: m[1]})
		return jsonContents(uri, out, err)
	}
	if m := deploymentURI.FindStringSubmatch(uri); m != nil {
		c, err := s.connect(ctx)
		if err != nil {
			return nil, err
		}
		if m[2] == "" {
			d, err := c.client.Deployment(ctx, m[1])
			if err != nil {
				return nil, explain(err)
			}
			return jsonContents(uri, s.outcome(ctx, c, d), nil)
		}
		w, err := readWindow(func(cursor int) ([]api.LogLine, api.LogMeta, error) {
			return c.client.DeploymentLogs(ctx, m[1], cursor)
		}, func(l api.LogLine) int { return l.Number }, nil, resourceLines)
		if err != nil {
			return nil, explain(err)
		}
		var b strings.Builder
		for _, l := range w.lines {
			b.WriteString(clip(l.Text) + "\n")
		}
		return []mcp.ResourceContents{{URI: uri, MimeType: "text/plain", Text: untrusted("deployment log", b.String())}}, nil
	}
	if m := runOutputURI.FindStringSubmatch(uri); m != nil {
		c, err := s.connect(ctx)
		if err != nil {
			return nil, err
		}
		w, err := readWindow(func(cursor int) ([]api.CommandOutputLine, api.LogMeta, error) {
			return c.client.CommandRunOutput(ctx, m[1], cursor)
		}, func(l api.CommandOutputLine) int { return l.Number }, nil, resourceLines)
		if err != nil {
			return nil, explain(err)
		}
		return []mcp.ResourceContents{{URI: uri, MimeType: "text/plain", Text: untrusted("command output", outputBody(w.lines))}}, nil
	}
	return nil, &mcp.RPCError{Code: mcp.CodeResourceNotFound, Message: "no resource " + uri}
}

func jsonContents(uri string, v any, err error) ([]mcp.ResourceContents, error) {
	if err != nil {
		return nil, err
	}
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return nil, err
	}
	return []mcp.ResourceContents{{URI: uri, MimeType: "application/json", Text: string(b)}}, nil
}
