package config

import (
	"errors"
	"fmt"
	"log/slog"
	"os"
	"strings"
)

// dockerd-guard's environment (from the install's .env through compose,
// never from launchroomd; docs/09 §6, D49).
const (
	// EnvGuardUpstream is the Docker socket the guard owns.
	EnvGuardUpstream = "LR_GUARD_UPSTREAM"
	// EnvGuardListen is the socket the guard serves launchroomd on.
	EnvGuardListen = "LR_GUARD_LISTEN"
	// EnvGuardAllowedRegistries lists extra workload registries ("host:port/,…").
	EnvGuardAllowedRegistries = "LR_GUARD_ALLOWED_REGISTRIES"
	// EnvGuardRootlessKitAPI is RootlessKit's API socket on rootless hosts (edge ports).
	EnvGuardRootlessKitAPI = "LR_GUARD_ROOTLESSKIT_API"

	DefaultGuardUpstream = "/var/run/docker.sock"
	DefaultGuardListen   = "/run/launchroom/docker.sock"
)

// Guard is dockerd-guard's configuration. It shares LR_SYSTEM_IMAGE_DIGESTS,
// LR_INTERACTIVE_SESSIONS and LR_LOG_LEVEL with launchroomd and parses them
// the same way.
type Guard struct {
	Upstream, Listen string
	// AllowedRegistries is LR_GUARD_ALLOWED_REGISTRIES as given (guard.PolicyFromEnv parses it).
	AllowedRegistries  string
	SystemImageDigests map[string][]string
	// InteractiveSessions is "on", "console", "data" or "off".
	InteractiveSessions string
	RootlessKitAPI      string
	LogLevel            slog.Level
}

// LoadGuard reads dockerd-guard's environment.
func LoadGuard(env Lookup) (Guard, error) {
	get := func(k string) string {
		v, _ := env(k)
		return strings.TrimSpace(v)
	}
	g := Guard{
		Upstream: orDefault(get(EnvGuardUpstream), DefaultGuardUpstream), Listen: orDefault(get(EnvGuardListen), DefaultGuardListen),
		AllowedRegistries: get(EnvGuardAllowedRegistries), RootlessKitAPI: get(EnvGuardRootlessKitAPI),
	}
	var errs []error
	digests, err := parseSystemImageDigests(get(EnvSystemImageDigests))
	if err != nil {
		errs = append(errs, err)
	}
	g.SystemImageDigests = digests
	sessions, err := parseInteractiveSessions(get(EnvInteractiveSessions))
	if err != nil {
		errs = append(errs, err)
	}
	g.InteractiveSessions = sessions
	if err := g.LogLevel.UnmarshalText([]byte(orDefault(get(EnvLogLevel), "info"))); err != nil {
		errs = append(errs, fmt.Errorf("config: invalid log level %q", get(EnvLogLevel)))
	}
	return g, errors.Join(errs...)
}

// GuardListen is the guard's socket for its healthcheck (no validation:
// the healthcheck only dials it).
func GuardListen() string {
	return orDefault(strings.TrimSpace(os.Getenv(EnvGuardListen)), DefaultGuardListen)
}
