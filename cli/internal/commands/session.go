package commands

import (
	"context"
	"errors"
	"fmt"
	"strings"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/config"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/link"
)

// session is a signed-in client for one dashboard.
type session struct {
	URL    string
	Client *api.Client
}

func (a *App) credentials() config.Credentials {
	c := config.Credentials{Dir: a.ConfigDir}
	if a.Keyring != nil && config.KeychainUsable() {
		c.Keyring = a.Keyring
	}
	return c
}

// currentLink is the link file of the working directory, if any.
func (a *App) currentLink() (*link.Link, string, error) {
	wd, err := a.Getwd()
	if err != nil {
		return nil, "", err
	}
	return link.Find(wd)
}

// dashboardURL: LAUNCHROOM_URL, else the link's URL, else the last login.
func (a *App) dashboardURL() (string, error) {
	if u := a.Getenv("LAUNCHROOM_URL"); u != "" {
		return config.NormalizeURL(u)
	}
	if l, _, err := a.currentLink(); err != nil {
		return "", err
	} else if l != nil && l.URL != "" {
		return config.NormalizeURL(l.URL)
	}
	cfg, err := config.Load(a.ConfigDir)
	if err != nil {
		return "", err
	}
	if cfg.Current == "" {
		return "", exitcode.New(exitcode.NotSignedIn, errors.New("not signed in to any launchroom yet: run `launchroom login --url https://your-launchroom`"))
	}
	return cfg.Current, nil
}

// session returns an authenticated client (LAUNCHROOM_TOKEN wins over the
// stored token, for CI).
func (a *App) session() (*session, error) {
	u, err := a.dashboardURL()
	if err != nil {
		return nil, err
	}
	token := a.Getenv("LAUNCHROOM_TOKEN")
	if token == "" {
		cfg, err := config.Load(a.ConfigDir)
		if err != nil {
			return nil, err
		}
		store := config.StoreFile
		if h, ok := cfg.Hosts[u]; ok {
			store = h.Store
		}
		token, err = a.credentials().Load(u, store)
		if errors.Is(err, config.ErrNoToken) {
			return nil, exitcode.New(exitcode.NotSignedIn, fmt.Errorf("not signed in to %s: run `launchroom login --url %s`", u, u))
		}
		if err != nil {
			return nil, err
		}
	}
	return &session{URL: u, Client: api.New(u, token, a.UserAgent())}, nil
}

// target is the project/environment/service a command acts on.
type target struct {
	Project     *api.Project
	Environment api.Environment
	Service     *api.Service
}

// resolve finds the target from flags and the link file. With needService,
// a service must be chosen (the link's, --service, the only one, or a
// prompt on a TTY).
func (a *App) resolve(ctx context.Context, s *session, needService bool) (*target, error) {
	l, _, err := a.currentLink()
	if err != nil {
		return nil, err
	}
	projectRef, envRef, serviceRef := a.flags.project, a.flags.environment, a.flags.service
	if l != nil {
		if projectRef == "" {
			projectRef = l.ProjectID
		}
		if envRef == "" && (a.flags.project == "" || a.flags.project == l.ProjectID) {
			envRef = l.EnvironmentID
		}
		if serviceRef == "" && (a.flags.environment == "" || a.flags.environment == l.EnvironmentID) {
			serviceRef = l.ServiceID
		}
	}
	t := &target{}
	switch {
	case strings.HasPrefix(envRef, "env_"):
		env, err := s.Client.Environment(ctx, envRef)
		if err != nil {
			return nil, err
		}
		t.Environment = env
	case projectRef == "":
		return nil, exitcode.Usagef("this directory is not linked to a project: run `launchroom link` (or pass --project and --environment)")
	default:
		project, err := a.findProject(ctx, s, projectRef)
		if err != nil {
			return nil, err
		}
		t.Project = &project
		env, err := a.findEnvironment(ctx, s, project, envRef)
		if err != nil {
			return nil, err
		}
		t.Environment = env
	}
	if !needService && serviceRef == "" {
		return t, nil
	}
	svc, err := a.findService(ctx, s, t.Environment, serviceRef)
	if err != nil {
		return nil, err
	}
	t.Service = &svc
	return t, nil
}

func (a *App) findProject(ctx context.Context, s *session, ref string) (api.Project, error) {
	if strings.HasPrefix(ref, "prj_") {
		return s.Client.Project(ctx, ref)
	}
	projects, err := s.Client.Projects(ctx)
	if err != nil {
		return api.Project{}, err
	}
	for _, p := range projects {
		if p.Slug == ref || strings.EqualFold(p.Name, ref) {
			return p, nil
		}
	}
	return api.Project{}, exitcode.New(exitcode.NotFound, fmt.Errorf("no project %q in this team", ref))
}

// findEnvironment picks by id or slug; empty ref = production.
func (a *App) findEnvironment(ctx context.Context, s *session, p api.Project, ref string) (api.Environment, error) {
	envs, err := s.Client.Environments(ctx, p.ID)
	if err != nil {
		return api.Environment{}, err
	}
	for _, e := range envs {
		if ref == "" && e.Kind == "production" || ref != "" && (e.ID == ref || e.Slug == ref || strings.EqualFold(e.Name, ref)) {
			return e, nil
		}
	}
	if ref == "" && len(envs) > 0 {
		return envs[0], nil
	}
	return api.Environment{}, exitcode.New(exitcode.NotFound, fmt.Errorf("no environment %q in %s", ref, p.Name))
}

func (a *App) findService(ctx context.Context, s *session, env api.Environment, ref string) (api.Service, error) {
	if strings.HasPrefix(ref, "svc_") {
		return s.Client.Service(ctx, env.ID, ref)
	}
	services, err := s.Client.Services(ctx, env.ID)
	if err != nil {
		return api.Service{}, err
	}
	if ref != "" {
		for _, svc := range services {
			if svc.Slug == ref || strings.EqualFold(svc.Name, ref) {
				return svc, nil
			}
		}
		return api.Service{}, exitcode.New(exitcode.NotFound, fmt.Errorf("no service %q in %s", ref, env.Name))
	}
	if len(services) == 0 {
		return api.Service{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no services yet", env.Name))
	}
	labels := make([]string, len(services))
	for i, svc := range services {
		labels[i] = fmt.Sprintf("%s (%s)", svc.Name, svc.Kind)
	}
	i, err := a.pick("service", labels)
	if err != nil {
		return api.Service{}, err
	}
	return services[i], nil
}
