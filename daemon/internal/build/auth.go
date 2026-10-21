package build

import (
	"context"
	"crypto/ed25519"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"strings"

	"github.com/docker/cli/cli/config/types"
	"github.com/moby/buildkit/session"
	"github.com/moby/buildkit/session/auth"
	"github.com/moby/buildkit/session/auth/authprovider"
	"golang.org/x/crypto/nacl/sign"
	"google.golang.org/grpc"
	"google.golang.org/grpc/codes"
	"google.golang.org/grpc/status"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TokenSource issues registry tokens (registry.Tokens).
type TokenSource interface {
	Token(ctx context.Context, scopes []state.RegistryScope, task state.TaskID) (secret.Secret, error)
}

// registryAuth is the BuildKit session auth provider of one build. For the
// launchroom registry it answers buildkitd's token requests with a
// dashboard-issued token restricted to the scopes this build may use
// (pull on mirror/…, pull+push on apps/<service> and cache/<service>);
// any other scope buildkitd asks for is dropped. Every other registry
// (Docker Hub, GHCR fallbacks) is anonymous. Nothing touches disk.
type registryAuth struct {
	auth.UnimplementedAuthServer
	host     string
	task     state.TaskID
	tokens   TokenSource
	allowed  func(state.RegistryRepository, state.RegistryAction) bool
	fallback auth.AuthServer
	seed     [32]byte
}

var _ session.Attachable = (*registryAuth)(nil)

func newRegistryAuth(host string, task state.TaskID, tokens TokenSource, allowed func(state.RegistryRepository, state.RegistryAction) bool) *registryAuth {
	anon := authprovider.NewDockerAuthProvider(authprovider.DockerAuthProviderConfig{
		AuthConfigProvider: func(context.Context, string, []string, authprovider.ExpireCachedAuthCheck) (types.AuthConfig, error) {
			return types.AuthConfig{}, nil
		},
	})
	fb, _ := anon.(auth.AuthServer)
	a := &registryAuth{host: host, task: task, tokens: tokens, allowed: allowed, fallback: fb}
	_, _ = rand.Read(a.seed[:])
	return a
}

func (a *registryAuth) Register(server *grpc.Server) { auth.RegisterAuthServer(server, a) }

// Credentials: never basic credentials; bearer tokens only.
func (a *registryAuth) Credentials(ctx context.Context, req *auth.CredentialsRequest) (*auth.CredentialsResponse, error) {
	if req.Host == a.host || a.fallback == nil {
		return &auth.CredentialsResponse{}, nil
	}
	return a.fallback.Credentials(ctx, req)
}

// authorityKey derives the per-build token authority key for a daemon salt.
// BuildKit only asks the session for tokens (FetchToken) when the session
// is a token authority for the host; the key never leaves memory.
func (a *registryAuth) authorityKey(salt []byte) ed25519.PrivateKey {
	mac := hmac.New(sha256.New, salt)
	mac.Write(a.seed[:])
	return ed25519.NewKeyFromSeed(mac.Sum(nil)[:ed25519.SeedSize])
}

// GetTokenAuthority makes this session the token source for the launchroom
// registry; other registries get Unimplemented (buildkitd then fetches
// anonymous tokens itself).
func (a *registryAuth) GetTokenAuthority(_ context.Context, req *auth.GetTokenAuthorityRequest) (*auth.GetTokenAuthorityResponse, error) {
	if req.Host != a.host {
		return nil, status.Error(codes.Unimplemented, "no token authority for this host")
	}
	pub, _ := a.authorityKey(req.Salt).Public().(ed25519.PublicKey)
	return &auth.GetTokenAuthorityResponse{PublicKey: pub}, nil
}

// VerifyTokenAuthority proves possession of the authority key.
func (a *registryAuth) VerifyTokenAuthority(_ context.Context, req *auth.VerifyTokenAuthorityRequest) (*auth.VerifyTokenAuthorityResponse, error) {
	if req.Host != a.host {
		return nil, status.Error(codes.Unimplemented, "no token authority for this host")
	}
	priv := new([64]byte)
	copy(priv[:], a.authorityKey(req.Salt))
	return &auth.VerifyTokenAuthorityResponse{Signed: sign.Sign(nil, req.Payload, priv)}, nil
}

// FetchToken issues the bearer token for a registry challenge.
func (a *registryAuth) FetchToken(ctx context.Context, req *auth.FetchTokenRequest) (*auth.FetchTokenResponse, error) {
	if req.Host != a.host {
		if a.fallback == nil {
			return nil, status.Error(codes.Unavailable, "no anonymous token provider")
		}
		return a.fallback.FetchToken(ctx, req)
	}
	scopes := a.filter(req.Scopes)
	if len(scopes) == 0 {
		return nil, status.Error(codes.PermissionDenied, "no registry scope this build may use")
	}
	tok, err := a.tokens.Token(ctx, scopes, a.task)
	if err != nil {
		return nil, status.Error(codes.PermissionDenied, "registry token: "+err.Error())
	}
	return &auth.FetchTokenResponse{Token: tok.Reveal(), ExpiresIn: 240}, nil
}

// filter parses Docker token-spec scopes ("repository:<name>:pull,push") and
// keeps only allowed repository actions.
func (a *registryAuth) filter(raw []string) []state.RegistryScope {
	byRepo := map[state.RegistryRepository][]state.RegistryAction{}
	var order []state.RegistryRepository
	for _, s := range raw {
		for _, one := range strings.Fields(s) {
			parts := strings.Split(one, ":")
			if len(parts) != 3 || parts[0] != "repository" {
				continue
			}
			repo := state.RegistryRepository(parts[1])
			if repo.Validate() != nil {
				continue
			}
			for _, act := range strings.Split(parts[2], ",") {
				ra := state.RegistryAction(act)
				if ra.Validate() != nil || !a.allowed(repo, ra) {
					continue
				}
				if _, seen := byRepo[repo]; !seen {
					order = append(order, repo)
				}
				dup := false
				for _, x := range byRepo[repo] {
					dup = dup || x == ra
				}
				if !dup {
					byRepo[repo] = append(byRepo[repo], ra)
				}
			}
		}
	}
	out := make([]state.RegistryScope, 0, len(order))
	for _, r := range order {
		if len(out) == 16 {
			break
		}
		out = append(out, state.RegistryScope{Repository: r, Actions: byRepo[r]})
	}
	return out
}
