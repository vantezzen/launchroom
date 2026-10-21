package build

import (
	"context"
	"crypto/ed25519"
	"crypto/subtle"
	"encoding/base64"
	"errors"
	"fmt"
	"io/fs"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	git "github.com/go-git/go-git/v5"
	"github.com/go-git/go-git/v5/config"
	"github.com/go-git/go-git/v5/plumbing"
	"github.com/go-git/go-git/v5/plumbing/transport"
	githttp "github.com/go-git/go-git/v5/plumbing/transport/http"
	gitssh "github.com/go-git/go-git/v5/plumbing/transport/ssh"
	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// cloneOptions configures fetching one commit.
type cloneOptions struct {
	Repo state.RepoSpec
	// Token authenticates an http(s) fetch (GitHub: installation token as the
	// x-access-token password; GitLab/Gitea: OAuth or access token as the
	// oauth2 password). It goes into the HTTP Authorization header in memory
	// only: never into the URL, the git config or the disk.
	Token secret.Secret
	// SSHKey is the base64 ed25519 seed of the deploy key for ssh:// URLs.
	SSHKey secret.Secret
	// InsecureHosts may be fetched over plain http (daemon-local config).
	InsecureHosts []string
	Progress      func(string)
}

// sshConnectTimeout bounds the TCP connect plus SSH handshake of a clone.
const sshConnectTimeout = 20 * time.Second

// refFallbackDepth is how much history of repo.ref is fetched when the
// server won't send the commit by its SHA.
const refFallbackDepth = 50

func init() {
	// Never read ~/.ssh/config: the remote, port and user come from the
	// signed task only.
	gitssh.DefaultSSHConfig = nil
}

// checkCloneURL enforces the scheme rules: https and ssh always, http only
// for hosts the daemon's local config allows (dev fixtures).
func checkCloneURL(raw state.GitURL, insecure []string) (*url.URL, error) {
	if err := raw.Validate(); err != nil {
		return nil, err
	}
	u, _ := url.Parse(string(raw))
	switch u.Scheme {
	case "https", "ssh":
		return u, nil
	case "http":
		if slices.Contains(insecure, u.Hostname()) {
			return u, nil
		}
		return nil, fmt.Errorf("plain http clone URLs are only allowed for hosts in LR_BUILD_INSECURE_GIT_HOSTS (got %s)", u.Hostname())
	default:
		return nil, fmt.Errorf("clone URL scheme %q is not allowed", u.Scheme)
	}
}

// basicAuthUser is the Basic-auth user name a provider expects with a token.
func basicAuthUser(p state.GitProvider) string {
	switch p {
	case state.GitHub:
		return "x-access-token"
	case state.GitLab, state.Gitea:
		return "oauth2"
	default:
		return "launchroom"
	}
}

// errHostKeyMismatch: the ssh server presented a key that is not pinned.
var errHostKeyMismatch = errors.New("host key mismatch")

// pinnedHostKeys builds a host key callback that accepts only the pinned
// keys, and the host key algorithms to offer (only the pinned types, so the
// server can't pick an unpinned key).
func pinnedHostKeys(pins []state.HostKey) (ssh.HostKeyCallback, []string, error) {
	if len(pins) == 0 {
		return nil, nil, errors.New("ssh clone URLs need pinned host keys")
	}
	keys := make([][]byte, 0, len(pins))
	var algos []string
	add := func(a ...string) {
		for _, x := range a {
			if !slices.Contains(algos, x) {
				algos = append(algos, x)
			}
		}
	}
	for _, p := range pins {
		if err := p.Validate(); err != nil {
			return nil, nil, err
		}
		pub, _ := p.PublicKey()
		keys = append(keys, pub.Marshal())
		if p.Type == state.HostKeyRSA {
			add(ssh.KeyAlgoRSASHA512, ssh.KeyAlgoRSASHA256, ssh.KeyAlgoRSA)
		} else {
			add(string(p.Type))
		}
	}
	cb := func(_ string, _ net.Addr, key ssh.PublicKey) error {
		got := key.Marshal()
		for _, k := range keys {
			if subtle.ConstantTimeCompare(k, got) == 1 {
				return nil
			}
		}
		return fmt.Errorf("%w: the server presented %s %s, which is not a pinned host key", errHostKeyMismatch, key.Type(), ssh.FingerprintSHA256(key))
	}
	return cb, algos, nil
}

// pinnedAuth is go-git ssh auth with a deploy key whose client config also
// restricts the host key algorithms (go-git leaves them to custom callbacks).
type pinnedAuth struct {
	*gitssh.PublicKeys
	algos []string
}

func (a *pinnedAuth) ClientConfig() (*ssh.ClientConfig, error) {
	c, err := a.PublicKeys.ClientConfig()
	if err != nil {
		return nil, err
	}
	c.HostKeyAlgorithms = slices.Clone(a.algos)
	c.Timeout = sshConnectTimeout
	return c, nil
}

// remoteURL is the URL go-git fetches from. Home-relative ssh paths
// (`/~/repos/app.git`, `/~user/app.git`) are handed over in go-git's
// scp-like form (`git@host[:port]:~/repos/app.git`), so the remote
// git-upload-pack gets `~/…` and expands it, as git's own client does.
func remoteURL(u *url.URL) string {
	if u.Scheme != "ssh" || !strings.HasPrefix(u.Path, "/~") {
		return u.String()
	}
	user := "git"
	if u.User != nil && u.User.Username() != "" {
		user = u.User.Username()
	}
	port := ""
	if p := u.Port(); p != "" {
		port = p + ":"
	}
	return user + "@" + u.Hostname() + ":" + port + u.Path[1:]
}

// sshAuth builds the deploy-key auth for an ssh:// remote.
func sshAuth(u *url.URL, opts cloneOptions) (transport.AuthMethod, error) {
	cb, algos, err := pinnedHostKeys(opts.Repo.KnownHosts)
	if err != nil {
		return nil, err
	}
	if opts.SSHKey.IsZero() {
		return nil, errors.New("ssh clone URLs need a deploy key")
	}
	seed, err := base64.StdEncoding.DecodeString(opts.SSHKey.Reveal())
	if err != nil || len(seed) != ed25519.SeedSize {
		return nil, errors.New("the deploy key is not a base64 ed25519 seed")
	}
	signer, err := ssh.NewSignerFromKey(ed25519.NewKeyFromSeed(seed))
	clear(seed)
	if err != nil {
		return nil, errors.New("the deploy key is unusable")
	}
	user := "git"
	if u.User != nil && u.User.Username() != "" {
		user = u.User.Username()
	}
	pk := &gitssh.PublicKeys{User: user, Signer: signer}
	pk.HostKeyCallback = cb
	return &pinnedAuth{PublicKeys: pk, algos: algos}, nil
}

type progressWriter func(string)

func (p progressWriter) Write(b []byte) (int, error) {
	for _, line := range strings.FieldsFunc(string(b), func(r rune) bool { return r == '\n' || r == '\r' }) {
		if line = strings.TrimSpace(line); line != "" {
			p(line)
		}
	}
	return len(b), nil
}

// clone fetches exactly opts.Repo.SHA (depth 1) into dir and checks it out
// detached. Submodules and LFS objects are not fetched. When the server
// won't send the commit by SHA and opts.Repo.Ref is set, the ref is fetched
// (depth 50) and the commit checked out from its history.
func clone(ctx context.Context, dir string, opts cloneOptions) error {
	u, err := checkCloneURL(opts.Repo.CloneURL, opts.InsecureHosts)
	if err != nil {
		return err
	}
	sha := string(opts.Repo.SHA)
	if len(sha) != 40 {
		return errors.New("only SHA-1 repositories are supported")
	}
	var auth transport.AuthMethod
	switch {
	case u.Scheme == "ssh":
		if auth, err = sshAuth(u, opts); err != nil {
			return err
		}
	case !opts.SSHKey.IsZero():
		return errors.New("a deploy key is only used with ssh clone URLs")
	case !opts.Token.IsZero():
		auth = &githttp.BasicAuth{Username: basicAuthUser(opts.Repo.Provider), Password: opts.Token.Reveal()}
	}
	repo, err := git.PlainInit(dir, false)
	if err != nil {
		return fmt.Errorf("init: %w", err)
	}
	if _, err := repo.CreateRemote(&config.RemoteConfig{Name: "origin", URLs: []string{remoteURL(u)}}); err != nil {
		return fmt.Errorf("remote: %w", err)
	}
	fetch := func(spec string, depth int) error {
		fo := &git.FetchOptions{
			RemoteName: "origin",
			RefSpecs:   []config.RefSpec{config.RefSpec(spec)},
			Depth:      depth,
			Auth:       auth,
			Tags:       git.NoTags,
		}
		if opts.Progress != nil {
			fo.Progress = progressWriter(opts.Progress)
		}
		if err := repo.FetchContext(ctx, fo); err != nil && !errors.Is(err, git.NoErrAlreadyUpToDate) {
			return err
		}
		return nil
	}
	if err := fetch(sha+":refs/heads/launchroom-build", 1); err != nil {
		ref := opts.Repo.Ref
		if ref == "" && errors.Is(err, git.ErrExactSHA1NotSupported) {
			// The server doesn't serve commits by SHA, but the commit may be
			// the tip of an advertised branch or tag.
			ref = advertisedRef(ctx, repo, auth, sha)
		}
		if !canFallBack(ctx, err, ref) {
			return fmt.Errorf("fetch %s: %w", sha, err)
		}
		if opts.Progress != nil {
			opts.Progress(fmt.Sprintf("The server did not send %s directly; fetching %s", sha, ref))
		}
		if ferr := fetch("+"+string(ref)+":refs/heads/launchroom-ref", refFallbackDepth); ferr != nil {
			return fmt.Errorf("fetch %s: %w (fetching %s: %w)", sha, err, ref, ferr)
		}
		if _, cerr := repo.CommitObject(plumbing.NewHash(sha)); cerr != nil {
			return fmt.Errorf("commit %s is not in the last %d commits of %s", sha, refFallbackDepth, opts.Repo.Ref)
		}
	}
	wt, err := repo.Worktree()
	if err != nil {
		return err
	}
	if err := wt.Checkout(&git.CheckoutOptions{Hash: plumbing.NewHash(sha), Force: true}); err != nil {
		return fmt.Errorf("checkout %s: %w", sha, err)
	}
	head, err := repo.Head()
	if err != nil {
		return err
	}
	if head.Hash().String() != sha {
		return fmt.Errorf("checked out %s, want %s", head.Hash(), sha)
	}
	return nil
}

// advertisedRef is a branch or tag of origin whose tip is sha, or "".
func advertisedRef(ctx context.Context, repo *git.Repository, auth transport.AuthMethod, sha string) state.GitRef {
	remote, err := repo.Remote("origin")
	if err != nil {
		return ""
	}
	refs, err := remote.ListContext(ctx, &git.ListOptions{Auth: auth})
	if err != nil {
		return ""
	}
	for _, r := range refs {
		name := state.GitRef(r.Name().String())
		if r.Type() == plumbing.HashReference && r.Hash().String() == sha && name.Validate() == nil {
			return name
		}
	}
	return ""
}

// Clone is the checkout step of Run on its own (exported for the
// integration tests): exactly repo.SHA into dir, with an http(s) token or an
// ssh deploy key (base64 ed25519 seed) and pinned host keys.
func Clone(ctx context.Context, dir string, repo state.RepoSpec, token, sshKey secret.Secret, insecureHosts []string) error {
	return clone(ctx, dir, cloneOptions{Repo: repo, Token: token, SSHKey: sshKey, InsecureHosts: insecureHosts})
}

// canFallBack: a failed fetch by SHA is retried at repo.ref unless the task
// was cancelled, the host key didn't match or credentials were refused.
func canFallBack(ctx context.Context, err error, ref state.GitRef) bool {
	return ref != "" && ctx.Err() == nil && !isHostKeyMismatch(err) &&
		!errors.Is(err, transport.ErrAuthenticationRequired) && !errors.Is(err, transport.ErrAuthorizationFailed) &&
		!strings.Contains(err.Error(), "unable to authenticate")
}

// isHostKeyMismatch reports a refused ssh host key (go-git does not always
// wrap the handshake error).
func isHostKeyMismatch(err error) bool {
	return err != nil && (errors.Is(err, errHostKeyMismatch) || strings.Contains(err.Error(), errHostKeyMismatch.Error()))
}

// errUnsafeRepo marks repository content the daemon refuses to build.
var errUnsafeRepo = errors.New("unsafe repository content")

// maxLinkHops bounds symlink resolution (the kernel's own limit is 40).
const maxLinkHops = 40

// checkTree rejects symlinks whose fully resolved target leaves the checkout
// and special files (docs/09 R4). Builds, Railpack and the preset scan read
// files from the checkout inside the daemon container (with /data keys and,
// through pid: host, /proc/1/root), so a link to anything outside must never
// exist when they run.
//
// Every symlink is resolved component by component the way the kernel does
// (resolveInRoot): links met on the way are expanded, ".." only ever pops a
// component that is already resolved, and the walk fails as soon as it would
// climb above the checkout or meets an absolute target. A purely lexical
// check is not enough: "a/b/s -> ../.." and "a/b/x -> s/../.." are both
// lexically inside, but x resolves two levels above the root.
//
// Railpack's planner reads the checkout with plain os calls (it has no fs.FS
// seam), so this check is what keeps it inside; the daemon's own reads go
// through os.Root as well (openApp), which refuses escapes independently.
func checkTree(root string) error {
	root, err := filepath.EvalSymlinks(root)
	if err != nil {
		return err
	}
	return filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() && d.Name() == ".git" && filepath.Dir(p) == root {
			return fs.SkipDir
		}
		rel, _ := filepath.Rel(root, p)
		switch t := d.Type(); {
		case t&fs.ModeSymlink != 0:
			if err := resolveInRoot(root, filepath.ToSlash(rel)); err != nil {
				return fmt.Errorf("%w: symlink %s %w", errUnsafeRepo, filepath.ToSlash(rel), err)
			}
		case t&(fs.ModeDevice|fs.ModeNamedPipe|fs.ModeSocket|fs.ModeCharDevice) != 0:
			return fmt.Errorf("%w: %s is not a regular file", errUnsafeRepo, filepath.ToSlash(rel))
		}
		return nil
	})
}

// pruneOutsideApp removes, outside the app folder dir, every symlink whose
// resolved target leaves the checkout and every special file, and returns
// their checkout-relative paths. They are not part of the build, but a
// monorepo may carry them (e.g. test fixtures that mimic /proc). Nothing
// inside dir is touched: checkTree still refuses unsafe entries there, and it
// runs over the whole checkout afterwards, so a link inside the app that
// pointed at a removed one now dangles instead of escaping. Links are removed
// themselves (os.Remove never follows them).
func pruneOutsideApp(root, dir string) ([]string, error) {
	root, err := filepath.EvalSymlinks(root)
	if err != nil {
		return nil, err
	}
	app, err := filepath.Rel(root, dir)
	if err != nil {
		return nil, err
	}
	if app == "." {
		return nil, nil // the app is the whole checkout: checkTree decides
	}
	app = filepath.ToSlash(app)
	// Removals go through os.Root: a path can't be redirected out of the
	// checkout between the walk and the remove.
	r, err := os.OpenRoot(root)
	if err != nil {
		return nil, err
	}
	defer func() { _ = r.Close() }()
	var removed []string
	err = filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(root, p)
		rel = filepath.ToSlash(rel)
		if d.IsDir() {
			if (d.Name() == ".git" && filepath.Dir(p) == root) || rel == app {
				return fs.SkipDir
			}
			return nil
		}
		if rel == app || strings.HasPrefix(rel, app+"/") {
			return nil
		}
		unsafe := false
		switch t := d.Type(); {
		case t&fs.ModeSymlink != 0:
			unsafe = resolveInRoot(root, rel) != nil
		case t&(fs.ModeDevice|fs.ModeNamedPipe|fs.ModeSocket|fs.ModeCharDevice) != 0:
			unsafe = true
		}
		if !unsafe {
			return nil
		}
		if err := r.Remove(filepath.FromSlash(rel)); err != nil {
			return err
		}
		removed = append(removed, rel)
		return nil
	})
	return removed, err
}

// prunedMessage names the entries pruneOutsideApp removed (at most five).
func prunedMessage(removed []string) string {
	shown := removed
	if len(shown) > 5 {
		shown = shown[:5]
	}
	msg := fmt.Sprintf("Removed %d symlink(s) or special file(s) outside the app folder that point outside the repository (not part of this build): %s", len(removed), strings.Join(shown, ", "))
	if len(removed) > len(shown) {
		msg += fmt.Sprintf(" and %d more", len(removed)-len(shown))
	}
	return msg
}

// resolveInRoot resolves the slash-separated path rel inside root, following
// every symlink on the way (including chains through other symlinks), and
// reports an error if resolution would leave root. Missing components are
// kept lexically: they cannot be links, and ".." after them still only pops
// components that are inside root.
func resolveInRoot(root, rel string) error {
	var cur []string // resolved, symlink-free components below root
	pending := strings.Split(rel, "/")
	hops := 0
	for len(pending) > 0 {
		c := pending[0]
		pending = pending[1:]
		switch c {
		case "", ".":
			continue
		case "..":
			if len(cur) == 0 {
				return errors.New("points outside the repository")
			}
			cur = cur[:len(cur)-1]
			continue
		}
		next := append(slices.Clone(cur), c)
		p := filepath.Join(append([]string{root}, next...)...)
		fi, err := os.Lstat(p)
		switch {
		case errors.Is(err, fs.ErrNotExist):
			cur = next
			continue
		case err != nil:
			return err
		case fi.Mode()&fs.ModeSymlink == 0:
			cur = next
			continue
		}
		if hops++; hops > maxLinkHops {
			return errors.New("has too many levels of symbolic links")
		}
		target, err := os.Readlink(p)
		if err != nil {
			return err
		}
		if filepath.IsAbs(target) || strings.HasPrefix(target, "/") || filepath.VolumeName(target) != "" {
			return errors.New("points to an absolute path")
		}
		// The target is relative to the link's directory (cur).
		pending = append(strings.Split(filepath.ToSlash(target), "/"), pending...)
	}
	return nil
}

// appDir resolves rootDir inside the checkout and makes sure it stays inside
// after resolving symlinks (docs/09 R4).
func appDir(checkout string, rootDir state.RelPath) (string, error) {
	base, err := filepath.EvalSymlinks(checkout)
	if err != nil {
		return "", err
	}
	if rootDir == "" {
		return base, nil
	}
	if err := rootDir.Validate(); err != nil {
		return "", err
	}
	p, err := filepath.EvalSymlinks(filepath.Join(base, filepath.FromSlash(string(rootDir))))
	if err != nil {
		return "", fmt.Errorf("root directory %s does not exist in the repository", rootDir)
	}
	if r, err := filepath.Rel(base, p); err != nil || r == ".." || strings.HasPrefix(r, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("%w: root directory %s resolves outside the repository", errUnsafeRepo, rootDir)
	}
	st, err := os.Stat(p)
	if err != nil || !st.IsDir() {
		return "", fmt.Errorf("root directory %s is not a directory", rootDir)
	}
	return p, nil
}

// fileIn resolves a repo-relative file inside dir (no escaping), or "" if missing.
func fileIn(dir string, rel state.RelPath) (string, error) {
	if err := rel.Validate(); err != nil {
		return "", err
	}
	p := filepath.Join(dir, filepath.FromSlash(string(rel)))
	r, err := filepath.EvalSymlinks(p)
	if err != nil {
		return "", nil //nolint:nilerr // missing file
	}
	if x, err := filepath.Rel(dir, r); err != nil || x == ".." || strings.HasPrefix(x, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("%w: %s resolves outside the app directory", errUnsafeRepo, rel)
	}
	st, err := os.Stat(r)
	if err != nil || !st.Mode().IsRegular() {
		return "", nil //nolint:nilerr // not a regular file → treated as missing
	}
	return r, nil
}
