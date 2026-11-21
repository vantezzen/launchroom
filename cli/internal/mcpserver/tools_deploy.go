package mcpserver

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/mcp"
)

func (s *server) deployTools() []entry {
	return []entry{
		on(mcp.NewTool("wait_for_deployment", "Wait for a deployment",
			"Follows a deployment until it is live, failed or cancelled, with progress per status and step. Name it by deployment, or by sha to wait for the deployment of a commit you pushed (it waits for the push to create it). A failed one comes back with its failure report. Never changes anything; when the wait runs out the deployment carries on.",
			readOnly, s.waitForDeployment), "service", api.APIAbilityRead),
		on(mcp.NewTool("deploy", "Deploy a service",
			"Deploys a service: a branch or tag (ref), a full commit sha, or without either the latest commit of the branch it tracks (an image service pulls its tag again). Waits for the result by default and answers the addresses when live, or the failure report. The previous version stays available for rollback.",
			additive, s.deploy), "service", api.APIAbilityDeploy),
		on(mcp.NewTool("redeploy", "Redeploy",
			"Builds and rolls out a deployment's commit again with the current settings and variables (default: the live deployment of the target service). Waits by default.",
			additive, s.redeploy), "service", api.APIAbilityDeploy),
		on(mcp.NewTool("rollback", "Roll back",
			"Brings back an earlier deployment of a service (default: the version before the live one). Database migrations are not reverted. In production, auto-deploy pauses until resumed. Ask the person first unless they asked for it. Waits by default.",
			destructive, s.rollback), "service", api.APIAbilityDeploy),
		on(mcp.NewTool("cancel_deployment", "Cancel a deployment",
			"Stops a deployment that is queued or in progress (or a staged one). The live version keeps serving. Ask the person first unless they asked for it.",
			destructiveIdempotent, s.cancelDeployment), "service", api.APIAbilityDeploy),
		on(mcp.NewTool("apply_staged_changes", "Apply staged changes",
			"Deploys every staged change of an environment (variables, settings, new services), like the dashboard's Deploy button, and waits for the deployments. Staged changes may include other people's edits: check with the person when unsure.",
			destructive, s.applyStaged), "project", api.APIAbilityDeploy),
		on(mcp.NewTool("apply_fix", "Apply the suggested fix",
			"Applies the one-click fix get_deployment_failure suggested for a failed deployment (code from its fixes; port or seconds override the suggestion), the same as the dashboard's \"Fix and redeploy\", and waits for the redeploy. Any other change is refused: it never changes a setting on a guess.",
			destructive, s.applyFix), "service", api.APIAbilityDeploy, api.APIAbilityFix),
	}
}

// ---- wait_for_deployment

// WaitForDeploymentIn names what to wait for.
type WaitForDeploymentIn struct {
	Deployment string `json:"deployment,omitempty" pattern:"^dpl_[0-7][0-9A-HJKMNP-TV-Z]{25}$" desc:"Deployment id (dpl_…)."`
	Sha        string `json:"sha,omitempty" pattern:"^[0-9a-f]{7,64}$" desc:"Wait for the target service's deployment of this commit (full or short sha), e.g. after a git push."`
	Where
	TimeoutSeconds int `json:"timeoutSeconds,omitempty" min:"10" max:"1800" desc:"Stop waiting after this long (default 600). The deployment carries on."`
}

func (s *server) waitForDeployment(ctx context.Context, req *mcp.Request, in WaitForDeploymentIn) (Outcome, string, error) {
	w := WaitIn{TimeoutSeconds: in.TimeoutSeconds}
	if in.Sha == "" {
		c, d, err := s.deploymentOf(ctx, in.Deployment, in.Where)
		if err != nil {
			return Outcome{}, "", err
		}
		return s.startAndFollow(ctx, req, c, d, w)
	}
	if in.Deployment != "" {
		return Outcome{}, "", mcp.Errorf("Pass deployment or sha, not both.")
	}
	c, t, err := s.targetOf(ctx, in.Where, true)
	if err != nil {
		return Outcome{}, "", err
	}
	deadline := s.opts.Now().Add(w.timeout())
	announced := false
	for {
		list, err := c.client.Deployments(ctx, t.Environment.ID, t.Service.ID, 10)
		if err != nil {
			return Outcome{}, "", explain(err)
		}
		for _, d := range list {
			if d.Commit != nil && strings.HasPrefix(d.Commit.Sha, in.Sha) {
				left := deadline.Sub(s.opts.Now())
				if left < time.Second {
					left = time.Second
				}
				o, err := s.follow(ctx, req, c, d, left)
				if err != nil {
					return Outcome{}, "", err
				}
				return o, outcomeText(o), nil
			}
		}
		if !announced {
			req.Progress(fmt.Sprintf("Waiting for %s to start deploying %s", t.Service.Name, shortSha(in.Sha)))
			announced = true
		}
		if !s.opts.Now().Before(deadline) {
			return Outcome{}, "", mcp.Errorf("No deployment of %s for commit %s appeared in time. Check that the push reached the branch %s tracks (list_deployments), or deploy it with deploy and sha.", t.Service.Name, shortSha(in.Sha), t.Service.Name)
		}
		if err := s.opts.Sleep(ctx, pollInterval); err != nil {
			return Outcome{}, "", err
		}
	}
}

func shortSha(sha string) string {
	if len(sha) > 7 {
		return sha[:7]
	}
	return sha
}

// ---- deploy

// DeployIn says what to deploy.
type DeployIn struct {
	Where
	Ref string `json:"ref,omitempty" maxLength:"255" pattern:"^(refs/heads/)?[A-Za-z0-9._/+-]+$" desc:"Branch or tag whose latest commit to deploy."`
	Sha string `json:"sha,omitempty" pattern:"^[0-9a-f]{40}([0-9a-f]{24})?$" desc:"Full commit sha to deploy."`
	WaitIn
}

func (s *server) deploy(ctx context.Context, req *mcp.Request, in DeployIn) (Outcome, string, error) {
	c, t, err := s.targetOf(ctx, in.Where, true)
	if err != nil {
		return Outcome{}, "", err
	}
	var d api.Deployment
	if in.Ref == "" && in.Sha == "" {
		d, err = c.client.DeployService(ctx, t.Environment.ID, t.Service.ID)
	} else {
		d, err = c.client.CreateDeployment(ctx, t.Environment.ID, api.DeployRequest{Service: t.Service.ID, Ref: in.Ref, Sha: in.Sha})
	}
	if err != nil {
		return Outcome{}, "", explain(err)
	}
	return s.startAndFollow(ctx, req, c, d, in.WaitIn)
}

// ---- redeploy

// RedeployIn names the deployment to build again.
type RedeployIn struct {
	Deployment string `json:"deployment,omitempty" pattern:"^dpl_[0-7][0-9A-HJKMNP-TV-Z]{25}$" desc:"Deployment to build again. Default: the live deployment of the target service."`
	Where
	WaitIn
}

func (s *server) redeploy(ctx context.Context, req *mcp.Request, in RedeployIn) (Outcome, string, error) {
	c, err := s.connect(ctx)
	if err != nil {
		return Outcome{}, "", err
	}
	id := in.Deployment
	if id == "" {
		t, err := s.resolve(ctx, c, in.Where, true)
		if err != nil {
			return Outcome{}, "", err
		}
		if t.Service.ActiveDeployment == nil {
			return Outcome{}, "", mcp.Errorf("%s has no live deployment to redeploy; use deploy.", t.Service.Name)
		}
		id = t.Service.ActiveDeployment.ID
	}
	d, err := c.client.DeploymentAction(ctx, id, "redeploy")
	if err != nil {
		return Outcome{}, "", explain(err)
	}
	return s.startAndFollow(ctx, req, c, d, in.WaitIn)
}

// ---- rollback

// RollbackOut is a rollback's outcome and what it left behind.
type RollbackOut struct {
	Outcome
	RolledBackTo     string `json:"rolledBackTo" desc:"The deployment brought back."`
	AutoDeployPaused bool   `json:"autoDeployPaused" desc:"Pushes don't deploy this environment until auto-deploy is resumed."`
}

func (s *server) rollback(ctx context.Context, req *mcp.Request, in RedeployIn) (RollbackOut, string, error) {
	c, err := s.connect(ctx)
	if err != nil {
		return RollbackOut{}, "", err
	}
	id := in.Deployment
	if id == "" {
		t, err := s.resolve(ctx, c, in.Where, true)
		if err != nil {
			return RollbackOut{}, "", err
		}
		list, err := c.client.Deployments(ctx, t.Environment.ID, t.Service.ID, 50)
		if err != nil {
			return RollbackOut{}, "", explain(err)
		}
		target, ok := rollbackTarget(list, t.Service)
		if !ok {
			return RollbackOut{}, "", mcp.Errorf("%s has no earlier version to roll back to: pass deployment (list_deployments).", t.Service.Name)
		}
		id = target.ID
	}
	d, err := c.client.DeploymentAction(ctx, id, "rollback")
	if err != nil {
		return RollbackOut{}, "", explain(err)
	}
	o, text, err := s.startAndFollow(ctx, req, c, d, in.WaitIn)
	if err != nil {
		return RollbackOut{}, "", err
	}
	out := RollbackOut{Outcome: o, RolledBackTo: id}
	if env, err := c.client.Environment(ctx, d.EnvironmentID); err == nil {
		out.AutoDeployPaused = env.AutoDeployPaused
	}
	text = fmt.Sprintf("Rolled back to %s. Database migrations were not reverted.\n", id) + text
	if out.AutoDeployPaused {
		text += "Auto-deploy of this environment is paused: pushes won't deploy until the person resumes it (dashboard banner, or `launchroom rollback --resume`).\n"
	}
	return out, text, nil
}

// rollbackTarget is the newest superseded deployment older than the live
// one: the version that ran before it.
func rollbackTarget(list []api.Deployment, svc *api.Service) (api.Deployment, bool) {
	seenLive := svc.ActiveDeployment == nil
	for _, d := range list {
		if svc.ActiveDeployment != nil && d.ID == svc.ActiveDeployment.ID {
			seenLive = true
			continue
		}
		if seenLive && d.Status == api.DeploymentStatusSuperseded {
			return d, true
		}
	}
	return api.Deployment{}, false
}

// ---- cancel_deployment

// CancelIn names the deployment to stop.
type CancelIn struct {
	Deployment string `json:"deployment" pattern:"^dpl_[0-7][0-9A-HJKMNP-TV-Z]{25}$" desc:"Deployment to cancel (dpl_…)."`
}

// CancelOut is the deployment after cancelling.
type CancelOut struct {
	Deployment api.Deployment `json:"deployment"`
}

func (s *server) cancelDeployment(ctx context.Context, _ *mcp.Request, in CancelIn) (CancelOut, string, error) {
	c, err := s.connect(ctx)
	if err != nil {
		return CancelOut{}, "", err
	}
	d, err := c.client.DeploymentAction(ctx, in.Deployment, "cancel")
	if err != nil {
		return CancelOut{}, "", explain(err)
	}
	return CancelOut{Deployment: d}, "Cancelled: " + deploymentLine(d) + ". The live version keeps serving.\n", nil
}

// ---- apply_staged_changes

// ApplyStagedIn picks the environment.
type ApplyStagedIn struct {
	Project     string `json:"project,omitempty" maxLength:"100" desc:"Project id (prj_…), slug or name. Default: the linked project."`
	Environment string `json:"environment,omitempty" maxLength:"100" desc:"Environment id (env_…), slug or name. Default: the linked environment, else production."`
	Message     string `json:"message,omitempty" maxLength:"500" desc:"What changed, shown with the deployments."`
	WaitIn
}

// ApplyStagedOut is every deployment the changes started.
type ApplyStagedOut struct {
	Revision    int       `json:"revision" desc:"The environment's new revision."`
	Deployments []Outcome `json:"deployments"`
}

func (s *server) applyStaged(ctx context.Context, req *mcp.Request, in ApplyStagedIn) (ApplyStagedOut, string, error) {
	c, t, err := s.targetOf(ctx, Where{Project: in.Project, Environment: in.Environment}, false)
	if err != nil {
		return ApplyStagedOut{}, "", err
	}
	res, err := c.client.DeployStaged(ctx, t.Environment.ID, in.Message)
	if err != nil {
		return ApplyStagedOut{}, "", explain(err)
	}
	out := ApplyStagedOut{Revision: res.Revision, Deployments: []Outcome{}}
	var b strings.Builder
	fmt.Fprintf(&b, "Applied %s's staged changes (revision %d): %s.\n", t.Environment.Name, res.Revision, plural(len(res.Deployments), "deployment", "deployments"))
	deadline := s.opts.Now().Add(in.timeout())
	for _, d := range res.Deployments {
		o := Outcome{Deployment: d, Finished: d.Finished}
		if in.wanted() {
			left := deadline.Sub(s.opts.Now())
			if left < time.Second {
				left = time.Second
			}
			if o, err = s.follow(ctx, req, c, d, left); err != nil {
				return ApplyStagedOut{}, "", err
			}
		}
		out.Deployments = append(out.Deployments, o)
		b.WriteString(outcomeText(o))
	}
	return out, capText(b.String()), nil
}

// ---- apply_fix

// ApplyFixIn names the failed deployment and the suggested fix.
type ApplyFixIn struct {
	Deployment string `json:"deployment" pattern:"^dpl_[0-7][0-9A-HJKMNP-TV-Z]{25}$" desc:"The failed deployment (dpl_…)."`
	Code       string `json:"code" enum:"set_port,raise_health_timeout,add_variable,generate_dockerfile,use_tcp_healthcheck,accept_4xx,add_capabilities,fix_volume_owner,free_disk" desc:"The fix's code from get_deployment_failure."`
	Port       *int   `json:"port,omitempty" min:"1" max:"65535" desc:"set_port: the port the app listens on, instead of the suggestion."`
	Seconds    *int   `json:"seconds,omitempty" min:"1" max:"3600" desc:"raise_health_timeout: the start period, instead of the suggestion."`
	WaitIn
}

// ApplyFixOut is what the fix did and the redeploy's outcome.
type ApplyFixOut struct {
	Result  string   `json:"result" enum:"deploying,staged,freeing_disk" desc:"deploying: the redeploy started; staged: the change waits for a deploy; freeing_disk: launchroom frees space first."`
	Message string   `json:"message"`
	Outcome *Outcome `json:"outcome,omitempty"`
}

func (s *server) applyFix(ctx context.Context, req *mcp.Request, in ApplyFixIn) (ApplyFixOut, string, error) {
	c, err := s.connect(ctx)
	if err != nil {
		return ApplyFixOut{}, "", err
	}
	res, err := c.client.ApplyFix(ctx, in.Deployment, in.Code, api.FixRequest{Port: in.Port, Seconds: in.Seconds})
	if err != nil {
		return ApplyFixOut{}, "", explain(err)
	}
	out := ApplyFixOut{Result: res.Outcome, Message: res.Message}
	text := res.Message + "\n"
	if res.Deployment != nil {
		o, more, err := s.startAndFollow(ctx, req, c, *res.Deployment, in.WaitIn)
		if err != nil {
			return ApplyFixOut{}, "", err
		}
		out.Outcome = &o
		text += more
	}
	return out, text, nil
}
