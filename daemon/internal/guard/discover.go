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

	"github.com/vantezzen/launchroom/daemon/internal/policy"
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

// DetectRootless reports whether the upstream engine is rootless.
func (s *Server) DetectRootless(ctx context.Context) (bool, error) {
	f, err := s.probeEngine(ctx)
	return f.Rootless, err
}

// DiscoverUpdater finds the guard's own compose container (by hostname,
// which Docker sets to the container id) and derives the self-update rule:
// the install folder is its compose working dir, the socket path on the
// host is the source of its upstream mount, and the supervisor may run the
// image of the install's launchroomd container. It fails when the guard
// does not run under compose (then self-updates are refused).
func (s *Server) DiscoverUpdater(ctx context.Context) (*UpdaterRule, error) {
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

// daemonImages returns the image ids of the install's launchroomd containers.
func (s *Server) daemonImages(ctx context.Context, project string) ([]string, error) {
	f, _ := json.Marshal(map[string]map[string]bool{"label": {
		composeProject + "=" + project: true, composeService + "=" + DaemonService: true, labelManaged + "=true": true,
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
		if reImageID.MatchString(c.ImageID) && !slices.Contains(out, c.ImageID) {
			out = append(out, c.ImageID)
		}
	}
	if len(out) == 0 {
		return nil, errors.New("no running launchroomd container in this install")
	}
	return out, nil
}

// splitList parses a comma-separated environment value.
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
// the caller with config.ParseSystemImageDigests).
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
