package mcpserver

import (
	"context"
	"fmt"
	"strings"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/mcp"
)

// Where names a project, environment and service; empty fields fall back
// to the link of the workspace.
type Where struct {
	Project     string `json:"project,omitempty" maxLength:"100" desc:"Project id (prj_…), slug or name. Default: the project this workspace is linked to."`
	Environment string `json:"environment,omitempty" maxLength:"100" desc:"Environment id (env_…), slug or name. Default: the linked environment, else production."`
	Service     string `json:"service,omitempty" maxLength:"100" desc:"Service id (svc_…), slug or name. Default: the linked service, else the only one."`
}

// Target is what Where resolved to.
type Target struct {
	Project     *api.Project
	Environment api.Environment
	Service     *api.Service
}

// resolve finds the target of w without asking anyone: a missing or
// ambiguous choice is an error that lists the candidates.
func (s *server) resolve(ctx context.Context, c *conn, w Where, needService bool) (*Target, error) {
	projectRef, envRef, serviceRef := w.Project, w.Environment, w.Service
	if l := c.link; l != nil {
		if projectRef == "" {
			projectRef = l.ProjectID
		}
		sameProject := w.Project == "" || w.Project == l.ProjectID
		if envRef == "" && sameProject {
			envRef = l.EnvironmentID
		}
		if serviceRef == "" && sameProject && (w.Environment == "" || w.Environment == l.EnvironmentID) {
			serviceRef = l.ServiceID
		}
	}
	// A scoped token reaches one project or service: that is the default too
	// (its routes refuse a wider ask, e.g. all of an environment's deployments).
	if me, err := s.whoami(ctx); err == nil && me.Scope != nil {
		if projectRef == "" {
			projectRef = me.Scope.ProjectID
		}
		if serviceRef == "" && me.Scope.ServiceID != nil {
			serviceRef = *me.Scope.ServiceID
		}
	}
	t := &Target{}
	if strings.HasPrefix(envRef, "env_") {
		env, err := c.client.Environment(ctx, envRef)
		if err != nil {
			return nil, explain(err)
		}
		t.Environment = env
	} else {
		project, err := s.findProject(ctx, c, projectRef)
		if err != nil {
			return nil, err
		}
		t.Project = &project
		if t.Environment, err = findEnvironment(ctx, c, project, envRef); err != nil {
			return nil, err
		}
	}
	if !needService && serviceRef == "" {
		return t, nil
	}
	svc, err := findService(ctx, c, t.Environment, serviceRef)
	if err != nil {
		return nil, err
	}
	t.Service = &svc
	return t, nil
}

func (s *server) findProject(ctx context.Context, c *conn, ref string) (api.Project, error) {
	if strings.HasPrefix(ref, "prj_") {
		p, err := c.client.Project(ctx, ref)
		return p, explain(err)
	}
	projects, err := c.client.Projects(ctx)
	if err != nil {
		return api.Project{}, explain(err)
	}
	if ref == "" {
		if len(projects) == 1 {
			return projects[0], nil
		}
		return api.Project{}, mcp.Errorf("This workspace isn't linked to a project (no .launchroom/project.json). Pass project, one of: %s. The person can link it with `launchroom link`.", projectNames(projects))
	}
	for _, p := range projects {
		if p.Slug == ref || strings.EqualFold(p.Name, ref) {
			return p, nil
		}
	}
	return api.Project{}, mcp.Errorf("No project %q in this team. Projects: %s.", ref, projectNames(projects))
}

func projectNames(projects []api.Project) string {
	if len(projects) == 0 {
		return "(none yet)"
	}
	names := make([]string, 0, len(projects))
	for i, p := range projects {
		if i == 20 {
			names = append(names, fmt.Sprintf("and %d more", len(projects)-20))
			break
		}
		names = append(names, fmt.Sprintf("%s (%s)", p.Slug, p.ID))
	}
	return strings.Join(names, ", ")
}

// findEnvironment picks by id, slug or name; empty means production.
func findEnvironment(ctx context.Context, c *conn, p api.Project, ref string) (api.Environment, error) {
	envs, err := c.client.Environments(ctx, p.ID)
	if err != nil {
		return api.Environment{}, explain(err)
	}
	for _, e := range envs {
		if ref == "" && e.Kind == api.EnvironmentKindProduction || ref != "" && (e.ID == ref || e.Slug == ref || strings.EqualFold(e.Name, ref)) {
			return e, nil
		}
	}
	if ref == "" && len(envs) > 0 {
		return envs[0], nil
	}
	names := make([]string, 0, len(envs))
	for _, e := range envs {
		names = append(names, e.Slug)
	}
	return api.Environment{}, mcp.Errorf("No environment %q in %s. Environments: %s.", ref, p.Name, strings.Join(names, ", "))
}

func findService(ctx context.Context, c *conn, env api.Environment, ref string) (api.Service, error) {
	if strings.HasPrefix(ref, "svc_") {
		svc, err := c.client.Service(ctx, env.ID, ref)
		return svc, explain(err)
	}
	services, err := c.client.Services(ctx, env.ID)
	if err != nil {
		return api.Service{}, explain(err)
	}
	for _, svc := range services {
		if ref != "" && (svc.Slug == ref || strings.EqualFold(svc.Name, ref)) || ref == "" && len(services) == 1 {
			return svc, nil
		}
	}
	if len(services) == 0 {
		return api.Service{}, mcp.Errorf("%s has no services yet.", env.Name)
	}
	names := make([]string, len(services))
	for i, svc := range services {
		names[i] = fmt.Sprintf("%s (%s)", svc.Slug, svc.Kind)
	}
	if ref == "" {
		return api.Service{}, mcp.Errorf("Which service? Pass service, one of: %s.", strings.Join(names, ", "))
	}
	return api.Service{}, mcp.Errorf("No service %q in %s. Services: %s.", ref, env.Name, strings.Join(names, ", "))
}

// targetOf connects and resolves in one step.
func (s *server) targetOf(ctx context.Context, w Where, needService bool) (*conn, *Target, error) {
	c, err := s.connect(ctx)
	if err != nil {
		return nil, nil, err
	}
	t, err := s.resolve(ctx, c, w, needService)
	return c, t, err
}

// deploymentOf is the deployment named by id, else the target service's
// latest one.
func (s *server) deploymentOf(ctx context.Context, id string, w Where) (*conn, api.Deployment, error) {
	c, err := s.connect(ctx)
	if err != nil {
		return nil, api.Deployment{}, err
	}
	if id != "" {
		d, err := c.client.Deployment(ctx, id)
		return c, d, explain(err)
	}
	t, err := s.resolve(ctx, c, w, true)
	if err != nil {
		return nil, api.Deployment{}, err
	}
	if t.Service.LatestDeployment == nil {
		return nil, api.Deployment{}, mcp.Errorf("%s has no deployments yet.", t.Service.Name)
	}
	return c, *t.Service.LatestDeployment, nil
}
