# 19: Git providers

Where code comes from. launchroom deploys from **GitHub**, **GitLab** (gitlab.com and self-managed), **Gitea/Forgejo** and **any git server** over SSH or HTTPS. All four feed the same deploy pipeline: a push deploys the environments whose trigger matches ([06](06-variables-environments.md) §2), a pull/merge request gets a preview ([06](06-variables-environments.md) §5), and the provider hears back how it went. Decision **D46** ([README](README.md)).

The rule for the user: *connect once, then every repository you can reach shows up in the import list.* The rule for the code: *everything after "a ref moved" is provider-neutral.*

## 1. What each provider gives us

| | GitHub | GitLab | Gitea / Forgejo | Any git server |
|---|---|---|---|---|
| Connect | GitHub App (manifest flow, one per team) | OAuth application + PKCE, or an access token | OAuth2 application + PKCE, or an access token | One repository at a time: URL, pinned host key, deploy key |
| Repository list | installation repositories (cached) | `GET /projects?membership&min_access_level=30` (live search, cached rows) | `GET /user/repos`, `GET /repos/search` (live search, cached rows) | the repository itself |
| Clone credential | installation token, one repository, minted per build | the connection's OAuth/access token (OAuth: expires after 2 h) | the connection's OAuth/access token (OAuth: 1 h) | ed25519 deploy key (SSH) or none (public HTTPS) |
| Push / PR events | app webhook, `X-Hub-Signature-256` | project webhook, `X-Gitlab-Token` | repository webhook, `X-Gitea-Signature` / `X-Forgejo-Signature` (HMAC-SHA256) | optional webhook with HMAC = "poll now"; polling otherwise |
| Polling fallback | conditional `GET /commits/{ref}` | `GET /repository/branches/{b}` | `GET /branches/{b}` | `ls-remote` (smart HTTP or SSH) |
| Deploy feedback | Deployments API + check runs | commit statuses (shown as an external pipeline) | commit statuses | none |
| Preview feedback | one PR comment table, a Deployment per preview | one MR note table, a commit status per preview | one PR comment table, a commit status per preview | none |
| Author avatars | `avatars.githubusercontent.com` | the connection's own origin, `secure.gravatar.com` | the connection's own origin, `secure.gravatar.com` | none (initials) |
| Read a file at a ref (D45, inspector) | contents API | `GET /repository/files/{path}/raw?ref=` | `GET /raw/{path}?ref=` | not available (no API); the build reads it |

GitHub uses the App, its installations, check runs and the Deployments API; its code sits behind the same contract as the others.

## 2. The provider contract

`App\Contracts\Git\GitProvider`, one implementation per `GitProviderKind` (`github`, `gitlab`, `gitea`, `git`), resolved through `App\Services\Git\GitProviders`. Every method takes a **`RepositoryRef`**: provider, connection id (`github_installations.id` for GitHub, `git_connections.id` otherwise), numeric repository id (GitHub repository id, GitLab project id, Gitea repository id; `0` for a plain git server), full name and team. Implementations look their own rows up **within that team** (docs/09 R18), so a ref built from one team's data can never reach another team's credentials.

```php
interface GitProvider
{
    public function kind(): GitProviderKind;
    public function searchRepositories(Team $team, string $search): array;            // list<SourceRepository>
    public function branches(SourceRepository $repository): array;                     // list<GitBranchData>
    public function defaultBranch(RepositoryRef $repository): ?string;
    public function head(RepositoryRef $repository, string $branch): CommitData;       // GitHeadUnavailable
    public function tree(RepositoryRef $repository, string $ref): RepositoryTree;      // file paths, complete flag
    public function fileAtRef(RepositoryRef $repository, string $path, string $ref): ?string; // RepositoryFileUnavailable
    public function cloneUrl(RepositoryRef $repository): string;
    public function cloneCredentials(RepositoryRef $repository): GitCredentials;       // token | ssh key seed + host keys
    public function changedFiles(RepositoryRef $repository, int $number, string $baseRef, string $headSha): ?array;
    public function pullRequestOpen(Preview $preview): ?bool;
    public function compareUrl(RepositoryRef $repository, string $from, string $to): ?string;
    public function reportDeployment(DeploymentStatusReport $report): void;            // never throws
    public function reportPreview(Preview $preview): void;                              // never throws
}
```

Code: `App\Contracts\Git\{GitProvider,GitAccount,SourceRepository,SshRemotes}`, `App\Services\Git\Providers\{GitHubProvider,ApiGitProvider,GitLabProvider,GiteaProvider,PlainGitProvider}`, `App\Services\Git\{GitProviders,GitConnectionSecrets,GitConnectionTokens,GitProviderApi,GitServerProbe,AvatarOrigins}`, `App\Services\Git\Ssh\{PhpseclibSshRemotes,DeployKey}`, `App\Support\Git\{SshUrl,HostKey,RefAdvertisement,ProviderAvatar,GitServerBranches}`. Picker repositories (`ghr_…` GitHub, `gir_…` everything else) implement `SourceRepository` and are resolved team-scoped by `GitProviders::repository()`; the import flow, the add-service sheet, plan previews, the inspector and branches all go through it.

Webhooks are not on the contract: each provider has its own receiver (different headers, different signatures) that ends in the same two neutral events, `GitPushReceived` and `PullRequestEventReceived`, carrying a **`GitAccount`** (the `GitHubInstallation` or `GitConnection` model: provider, team, id) and the numeric repository id. Listeners (`DeployOnGitPush`, `SyncPreviewsOnPullRequest`) match services on `(source.provider, source.installationId, source.repositoryId)`.

"File at ref" is provider-neutral: `GitProviders::forSource($source)->fileAtRef(RepositoryRef::fromSource($teamId, $source), $path, $ref)`, used by `launchroom.json` (D45, `ConfigFileReader`) and the import inspector. It returns null for a missing file and throws `RepositoryFileUnavailable` where the provider has no file API (plain git) or can't be reached, so callers fall back to what the build finds.

### 2.1 Services' `GitSource`

The stored JSON keeps its shape (revisions snapshot it): `provider` (`github` | `gitlab` | `gitea` | `git`), `installationId` = the connection row id of that provider, `repositoryId` = the provider's numeric id (0 for plain git), `fullName` (also `group/subgroup/project` paths, up to 5 segments), `cloneUrl` (plain git only: `https://`, `ssh://user@host[:port]/path`, `http://` for allow-listed dev hosts), `branch`, `rootDirectory`. A plain git source **without** `installationId` is the local-dev facility (`git-fixtures`, no credentials); with one it is a connected git server.

## 3. Data model

Tables (docs/07 "Git"), migration `2026_11_10_100000_add_git_providers`:

| Table | Columns | Notes |
|---|---|---|
| `git_connections` | `public_id` (`gic_`), `team_id`, `provider` (gitlab/gitea/git), `server_kind` (gitlab/gitea/forgejo/git, for the logo), `base_url` (web origin; plain git: the clone URL), `name`, `account_login`, `account_id`, `auth_method` (oauth/token/deploy_key/none), `oauth_client_id`, `wrapped_dek`, `kek_id`, `oauth_client_secret`🔒, `access_token`🔒, `refresh_token`🔒, `token_expires_at`, `webhook_secret`🔒, `ssh_private_key`🔒, `ssh_public_key`, `host_keys` (jsonb: `{type, key, fingerprint}`), `host_keys_confirmed_at`, `allow_private_network`, `status` (`GitConnectionStatus`: pending/active/needs_reauth), `status_reason`, `webhook_last_received_at`, `webhook_missed_at`, `repositories_synced_at`, `created_by` | One row per (team, provider account), unique when the account is known, or per plain git repository. Secrets: per-row DEK wrapped by the KEK (context `git_connection:<public_id>`), values bound to `git_connection\|team\|id\|field`; `$hidden`; decrypted only by `GitConnectionSecrets`; rewrapped by KEK rotation |
| `git_repositories` | `public_id` (`gir_`), `team_id`, `git_connection_id`, `external_id` (numeric; 0 for plain git), `full_name`, `default_branch`, `private`, `archived`, `pushed_at`, `web_url`, `hook_id`, `hook_error` | Cache of repositories seen through a connection (search results, signed webhook payloads, imports). `hook_id` is the webhook launchroom registered when a service first used the repository. Unique `(git_connection_id, external_id)` |
| `git_ref_heads` | `git_connection_id`, `external_id`, `ref`, `head_sha`, `last_polled_at`, `last_changed_at`, `awaiting_webhook_since` | Same job as `github_ref_heads`: one push is emitted once whether webhook or polling saw it first |
| `git_pull_request_comments` | `team_id`, `git_connection_id`, `external_id`, `pr_number`, `comment_id`, `rows` | The single MR/PR comment, edited in place (recreated if deleted) |
| `git_oauth_states` | `state_hash` (SHA-256 of the state), `git_connection_id`, `user_id`, `code_verifier` (encrypted with APP_KEY), `expires_at` | Pending OAuth authorizations; deleted when used |

Related columns: `webhook_deliveries.git_connection_id` (nullable; `github_app_id` stays for GitHub; delivery ids are stored as `<provider>:<uuid>`), `previews.git_connection_id`, `PreviewProvider` includes `gitlab` and `gitea`. Commit statuses need no table: a status with the same context (`launchroom: <service> (<environment>)`, `launchroom: preview`) replaces the previous one on both providers.

## 4. Connecting

Settings → Git is a **provider picker**: four calm cards (GitHub, GitLab, Gitea/Forgejo, Any git server) with monochrome marks, then one card per connection with a single health line (`Pushes arrive right away` / `A push didn't arrive by webhook: checking every minute` / `Checking for pushes every minute` / `Needs you to sign in again`; health rules in [06](06-variables-environments.md) §2.2). Owners and admins connect (`Permission::ManageGit`, same as the GitHub App); everyone sees the list.

### 4.1 GitLab and Gitea/Forgejo: OAuth with PKCE

The guided sheet has three steps:

1. **Where is it?** gitlab.com (preset) or the URL of a self-managed GitLab / Gitea / Forgejo. The dashboard checks it through `SafeHttpClient` (`/api/v4/version` → GitLab, `/api/v1/version` → Gitea; Forgejo reports `+gitea-` / `forgejo` in its version) and fills the logo. A host on a private network needs the **instance-owner override** "This server is on my private network" (audited, docs/09 R5 like backup destinations); only then is plain `http://` allowed.
2. **Create an application** on that server: a direct link to its page (`/-/user_settings/applications` on GitLab, `/user/settings/applications` on Gitea/Forgejo), the redirect URL to copy (`<APP_URL>/settings/git/oauth/callback`), the scope (`api` on GitLab; Gitea's default scopes), "Confidential" on. The user pastes the application id and secret.
3. **Authorize**: the browser goes to the provider's `/oauth/authorize` (GitLab) or `/login/oauth/authorize` (Gitea) with `state` (single-use, team- and user-bound, 15 minutes, stored server-side) and an S256 `code_challenge`; the callback exchanges the code with the `code_verifier` and the client secret, reads `/user`, and the connection becomes active.

Access tokens are refreshed under a cache lock shortly before expiry (`refresh_token` grant; both providers rotate the refresh token). A refused refresh marks the connection `needs_reauth`: builds fail at lease time with `clone_error` "Sign in to GitLab again in Settings → Git", and the card shows "Sign in again".

**Access token instead** (secondary link in the sheet): a personal, group or project access token with `api` scope (GitLab) or a Gitea token with `write:repository` + `read:user`. Same rows, `auth_method = token`, no refresh. This is also how the dev stack connects to its Forgejo (§8).

Why OAuth first: users already sign in to their git server; the token is short-lived and refreshable; nothing long-lived is pasted. Rejected as default: GitLab group/project access tokens (paid tier on gitlab.com, one per project), personal access tokens (long-lived, tied to a person's whole account), a system-wide GitLab "instance application" (needs GitLab admin).

The GitLab `api` scope is broad because webhooks, commit statuses and MR notes need it. Gitea/Forgejo OAuth tokens are likewise the user's full access unless the server restricts OAuth scopes. The token is sealed only to the leasing build server, for one task, and only launchroomd's in-process clone uses it; it never enters BuildKit, a build container, a URL, a git config or a log.

### 4.2 Any git server

"Add a repository" asks for one URL: `git@host:group/app.git`, `ssh://git@host:2222/app.git` or `https://host/app.git`.

- **SSH**: scp-style addresses are stored as `ssh://` URLs (`git@host:team/app.git` → `ssh://git@host/~/team/app.git`, home-relative like git itself; `git@host:/srv/app.git` → `ssh://git@host/srv/app.git`). The path grammar is `[A-Za-z0-9._~/-]` without `..` segments. The dashboard opens an SSH connection to the host (through the same host check as R5, IP resolved once and pinned; private networks need the owner override), reads its host keys (one connection per algorithm: ed25519, ECDSA P-256, RSA; key exchange only, no authentication), and shows them as `SHA256:…` fingerprints: "Check this matches what your git server shows (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`)". Only after the user confirms are they pinned. The dashboard then generates an **ed25519 deploy key** for this repository (libsodium; the private key is envelope-encrypted and never shown), shows the public key with "Add this as a read-only deploy key", and "Check access" runs an `ls-remote` with it. A changed host key later is never accepted silently: clones fail with `clone_error` "The server's host key changed", and the card offers "Check host key again" (fetch, compare, confirm).
- **HTTPS**: public repositories only (no credential); `ls-remote` over smart HTTP checks it.

Heads and polling for SSH remotes use the same SSH client (phpseclib3, pure PHP): authenticate with the deploy key, open an exec channel for `git-upload-pack '<path>'` (the git-over-SSH protocol request to the *remote* server's git service; the path is grammar-checked, so it can't break out of the quotes) and read the ref advertisement. Nothing runs on the dashboard's host.

Webhook (optional): `POST /webhooks/git/{gic_…}` signed with the connection's secret as `X-Launchroom-Signature: sha256=<hex HMAC-SHA256 of the raw body>` (also accepted: `X-Hub-Signature-256`, so Gitea, Forgejo, Bitbucket Server or a `post-receive` hook can call it). A valid call polls that repository's tracked refs right away; the body is not interpreted. The card's "Set up a webhook" fetches the URL and secret on request (`POST /settings/git/servers/{gic}/webhook`, owners and admins, audited `git.webhook_secret_revealed`); the secret is never part of a page's props.

## 5. Webhooks

| | Route | Authentication (fail closed, docs/09 R17) | Dedupe | Events |
|---|---|---|---|---|
| GitHub | `POST /webhooks/github` | `X-Hub-Signature-256` with the app's secret | `X-GitHub-Delivery` | push, pull_request, installation* |
| GitLab | `POST /webhooks/gitlab/{gic}` | `X-Gitlab-Token` = the connection's secret (`hash_equals`) | `X-Gitlab-Event-UUID` | Push Hook, Tag Push Hook, Merge Request Hook |
| Gitea/Forgejo | `POST /webhooks/gitea/{gic}` | `X-Forgejo-Signature` or `X-Gitea-Signature` = HMAC-SHA256 hex of the raw body | `X-Forgejo-Delivery` / `X-Gitea-Delivery` | push, pull_request |
| Any git | `POST /webhooks/git/{gic}` | `X-Launchroom-Signature` / `X-Hub-Signature-256` | none (idempotent "poll now") | any |

launchroom registers the GitLab/Gitea webhook itself when a repository is first used by a service (import or add service; `hook_id` on `git_repositories`), with the connection's secret, push + merge/pull request events and SSL verification on. It is not registered when the dashboard is not publicly reachable and the provider is gitlab.com (R19a: `WebhookReachability`); self-managed servers usually share a network and get it. Polling covers the rest. Deleting the last service of a repository leaves the hook (harmless: unmatched deliveries are ignored); disconnecting removes the hooks it registered (best effort).

Normalization: GitLab `Push Hook` / `Tag Push Hook` → `GitPushReceived` (`ref`, `before`, `after`, head commit from `commits[]` matching `checkout_sha`, changed files from `commits[].added/modified/removed` unless `total_commits_count > 20`, avatar from `user_avatar`); `Merge Request Hook` → `PullRequestEventReceived` (`iid` as the number, actions `open`/`reopen`/`update` with `oldrev` → opened/reopened/synchronize, `close`/`merge` → closed, fork = `source_project_id ≠ target_project_id`, labels from `labels[].title`). Gitea's payloads follow GitHub's (`push`, `pull_request` with `synchronized`). Fork authors' permission on GitLab comes from `GET /projects/:id/members/all/:user_id` (access level ≥ 30 = write), on Gitea from `GET /repos/{o}/{r}/collaborators/{user}/permission`; a failed lookup counts as no write access (R20).

## 6. Builds

`BuildTasks` puts `repo {provider, cloneUrl, sha, ref?, knownHosts?}` into the spec and references the source in `seal.cloneToken`; at lease time `TaskSecrets` asks the provider for `CloneCredentials` and seals either

- a **token** as `TaskToken` purpose `clone` → `spec.cloneToken` (GitHub: `x-access-token`, GitLab and Gitea: `oauth2` as the Basic-auth user), or
- the **deploy key** (the 32-byte ed25519 seed, base64) as `TaskToken` purpose `ssh_key` → `spec.sshKey`.

launchroomd clones in-process with go-git. For `ssh://` URLs it uses go-git's SSH transport (`golang.org/x/crypto/ssh`): no `ssh` binary, no `GIT_SSH`, no `core.sshCommand`, no known_hosts file. The host key callback accepts **only** a key equal to one of `repo.knownHosts` (type and bytes), and the client offers only those key types as host key algorithms, so the server can't switch to an unpinned one. A mismatch is `clone_error` "host key mismatch". `knownHosts` is required for SSH URLs and refused for HTTP(S) ones; `sshKey` only with SSH URLs, `cloneToken` only with HTTP(S) ones. When a server refuses to send an unadvertised commit (plain git servers don't allow fetching any SHA), launchroomd fetches `repo.ref` instead (the dashboard sends the deployment's ref, else the source's branch, for plain git sources) and checks the commit out if it is in the fetched history (depth 50); without `ref` it uses an advertised branch or tag whose tip is that commit. Home-relative paths (`/~/…`) are handed to go-git in scp form so the remote `git-upload-pack` expands `~`. The SSH connect and handshake time out after 20 s; the default SSH user is `git`.

These fields and the provider values `gitlab`/`gitea` need the daemon feature **`gitProviders`** ([03](03-daemon-protocol.md) §10); a build that needs them is only leased to servers announcing it.

## 7. Security notes

- Every provider API call goes through `SafeHttpClient`; private networks only with the owner override (then: RFC 1918, CGNAT/mesh and ULA, never loopback, link-local/metadata or the control plane's own service names). Credentials only in the `Authorization` header (`Bearer`; GitLab `PRIVATE-TOKEN` for access tokens is not used, Bearer works for both kinds).
- Webhook secrets: 32 random bytes per connection, envelope-encrypted. Verification stays on in local env.
- OAuth `state` is single-use and bound to team + user; the PKCE verifier never leaves the server; the client secret is envelope-encrypted.
- Deploy keys are generated per repository, so revoking one on the git server cuts exactly one repository off. Host keys are pinned on confirmation and never auto-updated.
- Avatars: `CommitData.authorAvatarUrl` is rebuilt from checked parts per provider (`GitHubAvatar`, `ProviderAvatar`: https only, host = the connection's origin or `secure.gravatar.com`, no credentials, other ports or fragments, a short checked path and query). The CSP's `img-src` lists `avatars.githubusercontent.com`, `secure.gravatar.com` and the https origins of active GitLab/Gitea connections (`AvatarOrigins`, cached 5 minutes, forgotten whenever a connection is saved or deleted). Plain git commits have no avatar (initials).
- Webhook routes take the connection's public id; the binding is not the session's team binding, verification is the secret. Unknown, pending or wrong-provider connections answer 401, like a bad signature.
- Clone URLs for GitLab/Gitea are built from the connection's origin and the repository's path (`<origin>/<full_name>.git`), never taken from an API answer. Repository paths from APIs and payloads are grammar-checked before they become a service source.

## 8. Local development

`dev/compose.yml` has a **Forgejo** service in profile `forgejo` (`codeberg.org/forgejo/forgejo:11-rootless`, digest-pinned, labelled, SQLite, built-in SSH on 2222; `http://forgejo:3000` on the compose network, `http://127.0.0.1:3300` for the browser). `make up-forgejo` starts it; `make test-git-e2e` (`dev/git-e2e.sh`) runs the whole path against it:

1. an admin user and token (`forgejo admin user …` in the container), a private repository seeded from `dev/fixtures/node-hello`;
2. `php artisan launchroom:dev:git-provider connect` (access token, instance owner, private network) and `… import` (project with previews on, deployed): the webhook is registered on Forgejo and the first deployment goes live;
3. a commit through Forgejo's contents API → signed webhook → a push deployment of exactly that commit;
4. a branch, a commit and a pull request → webhook → a running preview of the head commit, a `launchroom` commit status and the PR comment on Forgejo.

Dev wiring: the dashboard reaches Forgejo through the owner override; Forgejo delivers to `LAUNCHROOM_GIT_WEBHOOK_URL=http://dashboard:8000` (`launchroom.git.webhook_base_url`, for any setup where the git server reaches the dashboard under another address than `APP_URL`); the dev servers clone over plain http (`LR_BUILD_INSECURE_GIT_HOSTS=git-fixtures,forgejo`). GitLab is covered by HTTP fakes in Pest only (too heavy for the dev stack); OAuth is covered by Pest (the browser can't reach `forgejo:3000`).

### 8.1 Trying GitLab for real

1. Webhooks need GitLab to reach the dashboard: with gitlab.com that is a public `APP_URL` (or the dashboard domain, D43); otherwise launchroom doesn't register a webhook and pushes arrive by polling, every minute (previews need webhooks). A self-managed GitLab on your network can reach a LAN dashboard; then connect it with "This server is on my private network" (instance owner). If GitLab reaches the dashboard under another name, set `LAUNCHROOM_GIT_WEBHOOK_URL`.
2. On GitLab: avatar → Edit profile → **Applications** (`/-/user_settings/applications`; older versions `/-/profile/applications`; or a group/instance application) → Add new application: name `launchroom`, redirect URI `<APP_URL>/settings/git/oauth/callback` (only your browser follows it, so it needn't be public), **Confidential** on, scope **`api`**. Copy the Application ID and Secret.
3. In launchroom: Settings → Git → GitLab → gitlab.com (or your GitLab's URL) → paste the ID and secret → Authorize, and approve on GitLab.
4. `/projects/new` lists the projects you can push to (Developer access or more); importing one registers the webhook (Settings → Webhooks on the GitLab project shows it) and the first deploy builds with a token sealed to the build server. Commit statuses show up on commits and merge requests as an external pipeline stage; merge requests get one launchroom note with the preview table.
5. The build server's launchroomd must announce `gitProviders`; older ones never lease GitLab builds.

Alternative: a personal, group or project access token with scope `api` ("Use an access token instead").
