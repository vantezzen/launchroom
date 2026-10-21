package build

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// sshGitServer is an in-process SSH server that answers
// `git-upload-pack '<path>'` exec requests with the real git upload-pack
// (test only), accepting one authorized deploy key.
type sshGitServer struct {
	home string // $HOME of upload-pack (expands ~/ paths)
	addr string
	ln   net.Listener
	wg   sync.WaitGroup
}

func startSSHGitServer(t *testing.T, home string, authorized ssh.PublicKey, hostKeys ...ssh.Signer) *sshGitServer {
	t.Helper()
	cfg := &ssh.ServerConfig{
		PublicKeyCallback: func(_ ssh.ConnMetadata, key ssh.PublicKey) (*ssh.Permissions, error) {
			if bytes.Equal(key.Marshal(), authorized.Marshal()) {
				return &ssh.Permissions{}, nil
			}
			return nil, errors.New("unauthorized key")
		},
	}
	for _, k := range hostKeys {
		cfg.AddHostKey(k)
	}
	ln, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	s := &sshGitServer{home: home, addr: ln.Addr().String(), ln: ln}
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			s.wg.Add(1)
			go func() {
				defer s.wg.Done()
				s.serve(c, cfg)
			}()
		}
	}()
	t.Cleanup(func() {
		_ = ln.Close()
		s.wg.Wait()
	})
	return s
}

func (s *sshGitServer) serve(c net.Conn, cfg *ssh.ServerConfig) {
	defer func() { _ = c.Close() }()
	_ = c.SetDeadline(time.Now().Add(30 * time.Second))
	_, chans, reqs, err := ssh.NewServerConn(c, cfg)
	if err != nil {
		return
	}
	go ssh.DiscardRequests(reqs)
	for nc := range chans {
		if nc.ChannelType() != "session" {
			_ = nc.Reject(ssh.UnknownChannelType, "only sessions")
			continue
		}
		ch, chReqs, err := nc.Accept()
		if err != nil {
			return
		}
		go func() {
			defer func() { _ = ch.Close() }()
			for req := range chReqs {
				if req.Type != "exec" || len(req.Payload) < 4 {
					_ = req.Reply(false, nil)
					continue
				}
				n := binary.BigEndian.Uint32(req.Payload)
				cmd := string(req.Payload[4 : 4+n])
				path, ok := strings.CutPrefix(cmd, "git-upload-pack '")
				path, ok2 := strings.CutSuffix(path, "'")
				if !ok || !ok2 {
					_ = req.Reply(false, nil)
					continue
				}
				_ = req.Reply(true, nil)
				p := exec.Command("git", "upload-pack", "--", path) //nolint:forbidigo,gosec,noctx // test ssh server: fixed argv, no shell
				p.Env = append(os.Environ(), "GIT_CONFIG_NOSYSTEM=1", "HOME="+s.home)
				p.Stdin, p.Stdout, p.Stderr = ch, ch, ch.Stderr()
				status := uint32(0)
				if err := p.Run(); err != nil {
					status = 1
				}
				_, _ = ch.SendRequest("exit-status", false, binary.BigEndian.AppendUint32(nil, status))
				return
			}
		}()
	}
}

// gitRepo creates a bare repository with two commits on main and returns
// its path and the SHAs (oldest first).
func gitRepo(t *testing.T, allowAnySHA bool) (string, []string) {
	t.Helper()
	root := t.TempDir()
	work, bare := filepath.Join(root, "work"), filepath.Join(root, "repo.git")
	run := func(dir string, args ...string) string {
		cmd := exec.Command("git", args...) //nolint:forbidigo,gosec,noctx // test fixture: fixed argv, no shell
		cmd.Dir = dir
		cmd.Env = append(os.Environ(), "GIT_CONFIG_NOSYSTEM=1", "HOME=/nonexistent",
			"GIT_AUTHOR_NAME=t", "GIT_AUTHOR_EMAIL=t@example.invalid", "GIT_COMMITTER_NAME=t", "GIT_COMMITTER_EMAIL=t@example.invalid")
		out, err := cmd.CombinedOutput()
		if err != nil {
			t.Fatalf("git %v: %v\n%s", args, err, out)
		}
		return strings.TrimSpace(string(out))
	}
	if err := os.MkdirAll(work, 0o750); err != nil {
		t.Fatal(err)
	}
	run(work, "init", "-q", "-b", "main")
	var shas []string
	for i, body := range []string{"one", "two"} {
		if err := os.WriteFile(filepath.Join(work, "file.txt"), []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		run(work, "add", "-A")
		run(work, "commit", "-q", "--no-gpg-sign", "-m", "commit "+body)
		shas = append(shas, run(work, "rev-parse", "HEAD"))
		_ = i
	}
	run(root, "clone", "-q", "--bare", work, bare)
	if allowAnySHA {
		run(bare, "config", "uploadpack.allowAnySHA1InWant", "true")
	}
	return bare, shas
}

func hostKeyPin(t *testing.T, s ssh.Signer) state.HostKey {
	t.Helper()
	pub := s.PublicKey()
	return state.HostKey{Type: state.HostKeyType(pub.Type()), Key: state.HostKeyBlob(base64.StdEncoding.EncodeToString(pub.Marshal()))}
}

func newEd25519Signer(t *testing.T) (ssh.Signer, []byte) {
	t.Helper()
	seed := make([]byte, ed25519.SeedSize)
	if _, err := io.ReadFull(rand.Reader, seed); err != nil {
		t.Fatal(err)
	}
	s, err := ssh.NewSignerFromKey(ed25519.NewKeyFromSeed(seed))
	if err != nil {
		t.Fatal(err)
	}
	return s, seed
}

func TestSSHCloneWithPinnedHostKeys(t *testing.T) {
	if _, err := exec.LookPath("git"); err != nil {
		t.Skip("git binary not available for the test server")
	}
	hostEd, _ := newEd25519Signer(t)
	ecKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	hostEC, err := ssh.NewSignerFromKey(ecKey)
	if err != nil {
		t.Fatal(err)
	}
	other, _ := newEd25519Signer(t)
	deploy, seed := newEd25519Signer(t)
	stranger, strangerSeed := newEd25519Signer(t)
	_ = stranger

	strict, shas := gitRepo(t, false)
	loose, looseShas := gitRepo(t, true)
	srv := startSSHGitServer(t, filepath.Dir(strict), deploy.PublicKey(), hostEd, hostEC)
	url := func(path string) state.GitURL { return state.GitURL("ssh://git@" + srv.addr + path) }
	key := secret.Secret(base64.StdEncoding.EncodeToString(seed))

	cases := []struct {
		name    string
		repo    state.RepoSpec
		key     secret.Secret
		wantErr string
	}{
		{"pinned ed25519, tip commit", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url(strict), SHA: state.GitSHA(shas[1]), KnownHosts: []state.HostKey{hostKeyPin(t, hostEd)}}, key, ""},
		{"pinned ecdsa only: server must use it", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url(loose), SHA: state.GitSHA(looseShas[0]), KnownHosts: []state.HostKey{hostKeyPin(t, hostEC)}}, key, ""},
		{"wrong host key", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url(strict), SHA: state.GitSHA(shas[1]), KnownHosts: []state.HostKey{hostKeyPin(t, other)}}, key, "host key mismatch"},
		{"wrong deploy key", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url(strict), SHA: state.GitSHA(shas[1]), KnownHosts: []state.HostKey{hostKeyPin(t, hostEd)}}, secret.Secret(base64.StdEncoding.EncodeToString(strangerSeed)), "unable to authenticate"},
		{"unadvertised commit without ref", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url(strict), SHA: state.GitSHA(shas[0]), KnownHosts: []state.HostKey{hostKeyPin(t, hostEd)}}, key, "fetch"},
		{"unadvertised commit with ref fallback", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url(strict), SHA: state.GitSHA(shas[0]), Ref: "refs/heads/main", KnownHosts: []state.HostKey{hostKeyPin(t, hostEd)}}, key, ""},
		{"home-relative path", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url("/~/repo.git"), SHA: state.GitSHA(shas[1]), KnownHosts: []state.HostKey{hostKeyPin(t, hostEd)}}, key, ""},
		{"no deploy key", state.RepoSpec{Provider: state.GitGeneric, CloneURL: url(strict), SHA: state.GitSHA(shas[1]), KnownHosts: []state.HostKey{hostKeyPin(t, hostEd)}}, "", "deploy key"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ctx, cancel := context.WithTimeout(t.Context(), 60*time.Second)
			defer cancel()
			dir := filepath.Join(t.TempDir(), "src")
			err := clone(ctx, dir, cloneOptions{Repo: tc.repo, SSHKey: tc.key})
			if tc.wantErr == "" {
				if err != nil {
					t.Fatalf("clone: %v", err)
				}
				body, rerr := os.ReadFile(filepath.Join(dir, "file.txt")) //nolint:gosec // test path
				if rerr != nil || len(body) == 0 {
					t.Fatalf("checkout missing file: %v", rerr)
				}
				return
			}
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("err = %v, want %q", err, tc.wantErr)
			}
			if tc.wantErr == "host key mismatch" && !isHostKeyMismatch(err) {
				t.Fatalf("not classified as host key mismatch: %v", err)
			}
			if strings.Contains(err.Error(), string(tc.key)) && tc.key != "" {
				t.Fatal("error leaks the deploy key")
			}
		})
	}
}

func TestRemoteURL(t *testing.T) {
	for in, want := range map[string]string{
		"ssh://git@h/srv/app.git":             "ssh://git@h/srv/app.git",
		"ssh://h/~/repos/app.git":             "git@h:~/repos/app.git",
		"ssh://deploy@h:2222/~/repos/app.git": "deploy@h:2222:~/repos/app.git",
		"ssh://git@h/~alice/app.git":          "git@h:~alice/app.git",
		"https://h/~/x.git":                   "https://h/~/x.git",
	} {
		u, err := checkCloneURL(state.GitURL(in), nil)
		if err != nil {
			t.Fatal(in, err)
		}
		if got := remoteURL(u); got != want {
			t.Errorf("%s: %s, want %s", in, got, want)
		}
	}
}

func TestBasicAuthUser(t *testing.T) {
	for p, want := range map[state.GitProvider]string{state.GitHub: "x-access-token", state.GitLab: "oauth2", state.Gitea: "oauth2", state.GitGeneric: "launchroom"} {
		if got := basicAuthUser(p); got != want {
			t.Errorf("%s: %s, want %s", p, got, want)
		}
	}
}

func TestCloneRefusesKeyOnHTTPS(t *testing.T) {
	err := clone(t.Context(), t.TempDir(), cloneOptions{
		Repo:   state.RepoSpec{Provider: state.GitGeneric, CloneURL: "https://example.com/a.git", SHA: "4015b57a143aec5156fd1444a017a32137a3fd0f"},
		SSHKey: "c2VlZA==",
	})
	if err == nil || !strings.Contains(err.Error(), "deploy key") {
		t.Fatalf("err = %v", err)
	}
}
