package build

import (
	"context"
	"fmt"

	"github.com/moby/buildkit/session"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Checkout clones exactly repo.SHA into dir with the build's clone and
// checkout rules (docs/09 R4: pinned host keys, credentials in memory only,
// unsafe links outside the app folder removed, the whole tree checked) and
// returns the app directory (rootDir resolved inside the checkout). Used by
// inventory tasks (docs/22 §3.2), which read only manifests and lockfiles
// from it.
func Checkout(ctx context.Context, dir string, repo state.RepoSpec, rootDir state.RelPath, token, sshKey secret.Secret, insecureHosts []string) (string, error) {
	if err := clone(ctx, dir, cloneOptions{Repo: repo, Token: token, SSHKey: sshKey, InsecureHosts: insecureHosts}); err != nil {
		return "", fmt.Errorf("clone: %w", err)
	}
	app, err := appDir(dir, rootDir)
	if err != nil {
		return "", err
	}
	if _, err := pruneOutsideApp(dir, app); err != nil {
		return "", err
	}
	if err := checkTree(dir); err != nil {
		return "", err
	}
	return app, nil
}

// RegistryAuth is the BuildKit session auth provider of a task that is not
// a build: dashboard-issued tokens for the launchroom registry, restricted
// to the repositories allowed reports; anonymous for every other registry.
func RegistryAuth(host string, task state.TaskID, tokens TokenSource, allowed func(state.RegistryRepository, state.RegistryAction) bool) session.Attachable {
	return newRegistryAuth(host, task, tokens, allowed)
}
