//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"io"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"
	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// alpineImage hosts the test's OpenSSH + git server (packages installed at
// container start from the pinned base).
const alpineImage = "docker.io/library/alpine@sha256:5291449c3df73caf6ed85e649dec1b9e818b39a5d8c871e97afc13e9cd5e8fa8"

// sshGitSetup installs OpenSSH and git, creates the git user with the test's
// deploy key, a bare repository with one commit and starts sshd. /ready holds
// the commit SHA once everything is up.
const sshGitSetup = `set -eu
apk add --no-cache openssh-server git >/dev/null
ssh-keygen -A
adduser -D -s /usr/bin/git-shell git
sed -i 's/^git:!/git:*/' /etc/shadow
install -d -m 700 -o git -g git /home/git/.ssh
install -m 600 -o git -g git /seed/authorized_keys /home/git/.ssh/authorized_keys
mkdir -p /tmp/work /srv
cd /tmp/work
git init -q -b main
echo hello > file.txt
git add file.txt
git -c user.name=t -c user.email=t@example.invalid commit -q -m one
git clone -q --bare /tmp/work /srv/app.git
git -C /srv/app.git rev-parse HEAD > /tmp/sha
chown -R git:git /srv/app.git
mv /tmp/sha /ready
exec /usr/sbin/sshd -D -e
`

// TestSSHCloneAgainstOpenSSH clones over ssh from a real OpenSSH server with
// a deploy key and the pinned ed25519 host key, and refuses a wrong pin.
func TestSSHCloneAgainstOpenSSH(t *testing.T) {
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })
	pullImage(t, api, alpineImage)

	seed := make([]byte, ed25519.SeedSize)
	if _, err := io.ReadFull(rand.Reader, seed); err != nil {
		t.Fatal(err)
	}
	signer, err := ssh.NewSignerFromKey(ed25519.NewKeyFromSeed(seed))
	if err != nil {
		t.Fatal(err)
	}

	ctx := t.Context()
	labels := docker.ManagedLabels("srv_01J9Z8X7W6V5T4S3R2Q1P0ZZZZ")
	labels["launchroom.dev"] = "true"
	cr, err := api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Name:       "lr-itest-sshgit",
		Config:     &container.Config{Image: alpineImage, Labels: labels, Cmd: []string{"/bin/sh", "/seed/setup.sh"}},
		HostConfig: &container.HostConfig{},
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = api.ContainerRemove(t.Context(), cr.ID, client.ContainerRemoveOptions{Force: true})
	})
	if _, err := api.CopyToContainer(ctx, cr.ID, client.CopyToContainerOptions{DestinationPath: "/", Content: tarFiles(map[string][]byte{
		"seed/setup.sh":        []byte(sshGitSetup),
		"seed/authorized_keys": ssh.MarshalAuthorizedKey(signer.PublicKey()),
	})}); err != nil {
		t.Fatal(err)
	}
	if _, err := api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}

	sha := strings.TrimSpace(string(waitForFile(t, api, cr.ID, "/ready", 3*time.Minute)))
	hostPub, _, _, _, err := ssh.ParseAuthorizedKey(readContainerFile(t, api, cr.ID, "/etc/ssh/ssh_host_ed25519_key.pub"))
	if err != nil {
		t.Fatal(err)
	}
	insp, err := api.ContainerInspect(ctx, cr.ID, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	var ip netip.Addr
	for _, n := range insp.Container.NetworkSettings.Networks {
		ip = n.IPAddress
	}
	waitForPort(t, netip.AddrPortFrom(ip, 22).String())

	other, err := ssh.NewSignerFromKey(ed25519.NewKeyFromSeed(make([]byte, ed25519.SeedSize)))
	if err != nil {
		t.Fatal(err)
	}
	pin := func(k ssh.PublicKey) []state.HostKey {
		return []state.HostKey{{Type: state.HostKeyType(k.Type()), Key: state.HostKeyBlob(base64.StdEncoding.EncodeToString(k.Marshal()))}}
	}
	repo := state.RepoSpec{
		Provider: state.GitGeneric,
		CloneURL: state.GitURL("ssh://git@" + ip.String() + "/srv/app.git"),
		SHA:      state.GitSHA(sha),
		Ref:      "refs/heads/main",
	}
	key := secret.Secret(base64.StdEncoding.EncodeToString(seed))

	t.Run("pinned host key", func(t *testing.T) {
		repo := repo
		repo.KnownHosts = pin(hostPub)
		dir := filepath.Join(t.TempDir(), "src")
		if err := build.Clone(t.Context(), dir, repo, "", key, nil); err != nil {
			t.Fatalf("clone: %v", err)
		}
		if body, err := os.ReadFile(filepath.Join(dir, "file.txt")); err != nil || strings.TrimSpace(string(body)) != "hello" { //nolint:gosec // test path
			t.Fatalf("checkout: %q %v", body, err)
		}
	})
	t.Run("wrong host key", func(t *testing.T) {
		repo := repo
		repo.KnownHosts = pin(other.PublicKey())
		err := build.Clone(t.Context(), filepath.Join(t.TempDir(), "src"), repo, "", key, nil)
		if err == nil || !strings.Contains(err.Error(), "host key mismatch") {
			t.Fatalf("err = %v, want host key mismatch", err)
		}
	})
	t.Run("wrong deploy key", func(t *testing.T) {
		repo := repo
		repo.KnownHosts = pin(hostPub)
		err := build.Clone(t.Context(), filepath.Join(t.TempDir(), "src"), repo, "", secret.Secret(base64.StdEncoding.EncodeToString(make([]byte, ed25519.SeedSize))), nil)
		if err == nil || !strings.Contains(err.Error(), "unable to authenticate") {
			t.Fatalf("err = %v, want an authentication failure", err)
		}
	})
}

func readContainerFile(t *testing.T, api *client.Client, id, path string) []byte {
	t.Helper()
	b, err := containerFile(t, api, id, path)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func waitForFile(t *testing.T, api *client.Client, id, path string, timeout time.Duration) []byte {
	t.Helper()
	deadline := time.Now().Add(timeout)
	for {
		if b, err := containerFile(t, api, id, path); err == nil {
			return b
		}
		if time.Now().After(deadline) {
			t.Fatalf("%s did not appear in time:\n%s", path, tail(containerLogs(t, api, id), 40))
		}
		time.Sleep(time.Second)
	}
}

func containerFile(t *testing.T, api *client.Client, id, path string) ([]byte, error) {
	t.Helper()
	res, err := api.CopyFromContainer(t.Context(), id, client.CopyFromContainerOptions{SourcePath: path})
	if err != nil {
		return nil, err
	}
	defer func() { _ = res.Content.Close() }()
	tr := tar.NewReader(res.Content)
	if _, err := tr.Next(); err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	if _, err := io.Copy(&buf, io.LimitReader(tr, 1<<20)); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func waitForPort(t *testing.T, addr string) {
	t.Helper()
	deadline := time.Now().Add(60 * time.Second)
	for {
		c, err := (&net.Dialer{Timeout: 2 * time.Second}).DialContext(t.Context(), "tcp", addr)
		if err == nil {
			_ = c.Close()
			return
		}
		if time.Now().After(deadline) {
			t.Fatalf("%s not reachable: %v", addr, err)
		}
		time.Sleep(500 * time.Millisecond)
	}
}
