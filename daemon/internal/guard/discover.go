package guard

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Compose labels of the install's containers.
const (
	composeProject    = "com.docker.compose.project"
	composeWorkingDir = "com.docker.compose.project.working_dir"
	composeService    = "com.docker.compose.service"
	// DaemonService is launchroomd's compose service name.
	DaemonService = "launchroomd"
	// GuardService is the guard's compose service name.
	GuardService = "dockerd-guard"
)

// Engine facts the guard reads at start.
type engineFacts struct {
	Rootless bool
}

// probeEngine reads whether the engine runs rootless (docker info
// SecurityOptions "name=rootless").
func (s *Server) probeEngine(ctx context.Context) (engineFacts, error) {
	status, raw, err := s.get(ctx, nil, "/info", nil)
	if err != nil {
		return engineFacts{}, err
	}
	if status != http.StatusOK {
		return engineFacts{}, fmt.Errorf("docker info: HTTP %d", status)
	}
	var info struct {
		SecurityOptions []string
	}
	if err := json.Unmarshal(raw, &info); err != nil {
		return engineFacts{}, err
	}
	return engineFacts{Rootless: slices.Contains(info.SecurityOptions, "name=rootless")}, nil
}

// Discover waits for Docker, then learns the host: whether it runs
// rootless (edge ports through RootlessKit's API at rootlessKitAPI, if
// given) and the install's compose project, which allows self-updates.
// Call it once, before Serve. It returns only when ctx ends first.
func (s *Server) Discover(ctx context.Context, rootlessKitAPI string) error {
	var facts engineFacts
	for i := 0; ; i++ {
		pctx, cancel := context.WithTimeout(ctx, 10*time.Second)
		f, err := s.probeEngine(pctx)
		cancel()
		if err == nil {
			facts = f
			break
		}
		if i%10 == 0 {
			s.o.Log.Warn("waiting for Docker", "upstream", s.o.Upstream, "err", err)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(2 * time.Second):
		}
	}
	if facts.Rootless {
		s.o.Rootless = &RootlessOptions{APISocket: rootlessKitAPI}
		s.o.Log.Info("rootless Docker host", "edgePorts", rootlessKitAPI != "")
	}
	if u, err := s.discoverUpdater(ctx); err != nil {
		s.o.Log.Warn("self-updates through the guard are disabled", "reason", err)
	} else {
		s.o.Updater = u
		s.o.Log.Info("self-update supervisor allowed", "installFolder", u.ProjectDir)
	}
	return nil
}

// discoverUpdater finds the guard's own compose container (by hostname,
// which Docker sets to the container id) and derives the self-update rule:
// the install folder is its compose working dir, the socket path on the
// host is the source of its upstream mount, and the supervisor may run the
// image of the install's launchroomd container. It fails when the guard
// does not run under compose (then self-updates are refused).
func (s *Server) discoverUpdater(ctx context.Context) (*UpdaterRule, error) {
	host, err := os.Hostname()
	if err != nil {
		return nil, err
	}
	if !reObject.MatchString(host) {
		return nil, errors.New("hostname is not a container id")
	}
	status, raw, err := s.get(ctx, nil, "/containers/"+host+"/json", nil)
	if err != nil {
		return nil, err
	}
	if status != http.StatusOK {
		return nil, fmt.Errorf("own container %s: HTTP %d", host, status)
	}
	var self struct {
		Config struct {
			Labels map[string]string
		}
		Mounts []struct {
			Type        string
			Source      string
			Destination string
		}
	}
	if err := json.Unmarshal(raw, &self); err != nil {
		return nil, err
	}
	l := self.Config.Labels
	project, dir := l[composeProject], l[composeWorkingDir]
	if project == "" || dir == "" || l[composeService] != GuardService {
		return nil, errors.New("dockerd-guard does not run as the compose service " + GuardService)
	}
	if !filepath.IsAbs(dir) || filepath.Clean(dir) != dir || dir == "/" {
		return nil, fmt.Errorf("compose working dir %q is not a clean absolute path", dir)
	}
	var socket string
	for _, m := range self.Mounts {
		if m.Type == "bind" && m.Destination == s.o.Upstream {
			socket = m.Source
		}
	}
	if socket == "" || !filepath.IsAbs(socket) {
		return nil, errors.New("the Docker socket is not bind-mounted into dockerd-guard")
	}
	rule := &UpdaterRule{ProjectDir: dir, SocketHostPath: socket}
	rule.Images = func(ctx context.Context) ([]string, error) { return s.daemonImages(ctx, project) }
	return rule, nil
}

func (s *Server) daemonImages(ctx context.Context, project string) ([]string, error) {
	f, _ := json.Marshal(map[string]map[string]bool{"label": {
		composeProject + "=" + project: true, composeService + "=" + DaemonService: true, names.LabelManaged + "=true": true,
	}})
	status, raw, err := s.get(ctx, nil, "/containers/json", url.Values{"filters": {string(f)}})
	if err != nil {
		return nil, err
	}
	if status != http.StatusOK {
		return nil, fmt.Errorf("list containers: HTTP %d", status)
	}
	var list []struct {
		ImageID string
	}
	if err := json.Unmarshal(raw, &list); err != nil {
		return nil, err
	}
	var out []string
	for _, c := range list {
		if state.IsImageID(c.ImageID) && !slices.Contains(out, c.ImageID) {
			out = append(out, c.ImageID)
		}
	}
	if len(out) == 0 {
		return nil, errors.New("no running launchroomd container in this install")
	}
	return out, nil
}

func splitList(v string) []string {
	var out []string
	for _, x := range strings.Split(v, ",") {
		if x = strings.TrimSpace(x); x != "" {
			out = append(out, x)
		}
	}
	return out
}

// PolicyFromEnv builds the guard's policy from its own environment:
// launchroomd's defaults plus LR_GUARD_ALLOWED_REGISTRIES (extra workload
// registries, prefixes ending in "/") and system image digests (parsed by
// config.LoadGuard).
func PolicyFromEnv(base policy.Config, extraRegistries string, digests map[string][]string) (policy.Config, error) {
	p := base
	for _, r := range splitList(extraRegistries) {
		if !slices.Contains(p.AllowedRegistries, r) {
			p.AllowedRegistries = append(p.AllowedRegistries, r)
		}
	}
	for comp, ds := range digests {
		p.SystemImageDigests[comp] = append(p.SystemImageDigests[comp], ds...)
	}
	return p, p.Validate()
}
