<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Envelope encryption (docs/09-security.md R13)
    |--------------------------------------------------------------------------
    |
    | The key-encryption key (KEK) lives in its own file, never in APP_KEY or
    | the database. It wraps per-project data-encryption keys (DEKs). Create
    | it with `php artisan launchroom:keys:generate`.
    |
    */

    'kek_path' => env('LAUNCHROOM_KEK_PATH') ?: storage_path('keys/kek.key'),

    /*
    |--------------------------------------------------------------------------
    | Control-plane signing key (docs/03 §4.1, docs/09 R22)
    |--------------------------------------------------------------------------
    |
    | Ed25519 key that signs every response the daemon acts on. A file, not
    | the database or APP_KEY; daemons pin its public key at install. Created
    | by `php artisan launchroom:keys:generate` (never overwritten).
    |
    */

    'signing_key_path' => env('LAUNCHROOM_SIGNING_KEY_PATH') ?: storage_path('keys/signing.ed25519'),

    /*
    |--------------------------------------------------------------------------
    | Release version and updates (docs/11 §4)
    |--------------------------------------------------------------------------
    |
    | version: this dashboard's release, from the repo's VERSION file (the
    | image sets LAUNCHROOM_VERSION at build time). It is also the version
    | servers are rolled to by "Update all servers".
    |
    | updates: where Settings → Updates looks for new releases (the GitHub
    | Releases API of the repository) and which channel it offers: stable
    | skips prereleases, beta includes them. Settings → Updates can change
    | it; without a choice there or here, a pre-release version (0.1.0-rc.3)
    | is on beta and any other on stable. The daemons verify
    | what they install against the release identity pinned at install, so
    | nothing here is trusted for integrity.
    |
    */

    'version' => env('LAUNCHROOM_VERSION', '0.0.0-dev'),

    'updates' => [
        'repository' => env('LAUNCHROOM_UPDATES_REPOSITORY', 'vantezzen/launchroom'),
        'api_base' => env('LAUNCHROOM_UPDATES_API', 'https://api.github.com'),
        // stable|beta; unset (or empty) follows the running version: a
        // pre-release such as 0.1.0-rc.3 is on beta, anything else stable.
        'channel' => env('LAUNCHROOM_UPDATES_CHANNEL'),
        'check_ttl_seconds' => 3600,
        // Automatic updates (opt-in, Settings → Updates) start only in this
        // window of the control-plane server's time zone (hours, end exclusive).
        'auto_window' => ['start' => 2, 'end' => 5],
        // Release assets redirect once to GitHub's storage hosts.
        'asset_hosts' => ['objects.githubusercontent.com', 'release-assets.githubusercontent.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Teams, invitations and first-run setup (docs/09 R10)
    |--------------------------------------------------------------------------
    */

    'invitations' => [
        'ttl_days' => 7,
    ],

    'setup' => [
        'token_ttl_minutes' => (int) env('LAUNCHROOM_SETUP_TOKEN_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daemons (docs/03)
    |--------------------------------------------------------------------------
    */

    'daemon' => [
        // Join tokens (docs/09 R21: ≤ 60 min).
        'enrollment_ttl_minutes' => 30,

        // Request signatures: allowed clock skew and nonce replay window
        // (protocol/SIGNING.md §2.4). Nonces live in this cache store, which
        // must be shared by every web worker (Valkey in dev and production).
        'max_clock_skew_seconds' => 60,
        'nonce_ttl_seconds' => 300,
        'nonce_cache_store' => env('LAUNCHROOM_NONCE_CACHE_STORE'),

        // Polling fallback when Reverb is down (docs/03 §4).
        'poll_intervals' => ['state' => '30s', 'tasks' => '10s'],

        // Realtime nudges (docs/03 §4): content-free "poll now" events on the
        // private channel daemon.{server} of the default broadcaster (Reverb).
        // Daemons get the WebSocket URL from GET /daemon/v1/config; without
        // nudge_url they derive it from their control-plane URL (ws(s)://<host>/app/<key>,
        // which the dashboard proxies to Reverb). Dev: ws://reverb:6001.
        'nudges' => (bool) env('LAUNCHROOM_DAEMON_NUDGES', true),
        'nudge_url' => env('LAUNCHROOM_DAEMON_NUDGE_URL'),

        // Liveness (docs/03 §9): two missed state polls → unreachable.
        'unreachable_after_seconds' => 75,
        'offline_after_seconds' => 300,

        // Task leases (docs/03 §7).
        'lease_seconds' => 60,

        // Client certificates for daemon transport (mTLS, D50, docs/03 §4.3):
        // lifetime of a server certificate (renewed at two thirds of it) and
        // the issuance budget per server and hour.
        'client_certificates' => [
            'lifetime_hours' => 168,
            'max_per_hour' => 12,
            'reissue_window_minutes' => 60,
            // A server is "ready" when it presented a verified certificate this recently.
            'ready_within_minutes' => 15,
        ],

        // Protocol versions this dashboard speaks, newest first (docs/03 §10):
        // the current one and N-1. Daemon requests whose X-Lr-Protocol-Versions
        // header shares none of them are refused with 426.
        'protocol_versions' => ['v1'],

        // Where the Docker daemon pulls from (the local forwarder or Zot).
        'registry_address' => env('LAUNCHROOM_REGISTRY_ADDRESS', '127.0.0.1:5000'),

        // Daemon image (digest-pinned) that /install/server.sh installs on new
        // servers; set from the release's image pins (deploy/control-plane).
        'image' => env('LAUNCHROOM_DAEMON_IMAGE'),

        // System container images, always digest-pinned (docs/09 R31). The
        // daemon only accepts the system-image digests it was built with, so
        // these must match the daemon release. BuildKit is
        // moby/buildkit:v0.34.0-rootless (daemon policy.BuildkitImageDigest).
        // Caddy: release installs set LAUNCHROOM_CADDY_IMAGE to the release's
        // launchroom-caddy (images.env LR_CADDY_IMAGE, the digest compiled
        // into launchroomd); the fallback is stock caddy:2.11.7, which dev
        // builds of launchroomd accept (policy.StockCaddyImageDigest).
        'system_images' => [
            'caddy' => env('LAUNCHROOM_CADDY_IMAGE', 'docker.io/library/caddy@sha256:685ae3d7c34d9b5f8caeddc5d14051db7bbd88ff1f5a2fa0c16bd8ca328fb7af'),
            'buildkit' => env('LAUNCHROOM_BUILDKIT_IMAGE', 'docker.io/moby/buildkit@sha256:65eca3c01cd1180c8f64565c4e3c5983af5145818b0e6fa863385d099eb73855'),
            // tailscale/tailscale v1.104.1 (multi-arch index), the mesh client (docs/05 §2).
            'tailscale' => env('LAUNCHROOM_TAILSCALE_IMAGE', 'docker.io/tailscale/tailscale@sha256:87c55d6be56ad85b3d8bceca5f87f73de288c609131460dd1be1874c250cc41d'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Control-plane install (docs/11 §2-3, docs/05 §4)
    |--------------------------------------------------------------------------
    |
    | dashboard_upstream: the dashboard's address as seen from the control-plane
    | host ("127.0.0.1:8000" in deploy/control-plane/compose.yml). When set, the
    | control-plane server's NodeState routes APP_URL's host there through the
    | daemon's Caddy. Unset (dev, tests): no dashboard route.
    |
    | scripts_path: where the daemon installer (server.sh + daemon-compose.yml from
    | deploy/) lives; the production image copies them to /var/www/install.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Server health (docs/03 §1, docs/04 §8)
    |--------------------------------------------------------------------------
    |
    | Thresholds for the checks derived from the host facts daemons report
    | every few minutes (App\Queries\ServerHealth).
    |
    */

    'server_health' => [
        // Facts older than this are shown as stale.
        'stale_after_seconds' => 900,
        // Free disk (Docker's filesystem, else the daemon data dir), percent.
        'disk_free_warning_percent' => 20,
        'disk_free_critical_percent' => 10,
        // Available memory, percent.
        'memory_available_warning_percent' => 15,
        'memory_available_critical_percent' => 5,
        // 5-minute load per CPU.
        'load_per_cpu_warning' => 2.0,
        'load_per_cpu_critical' => 4.0,
        // inotify limits below these break file watchers and many containers.
        'inotify_min_watches' => 65536,
        'inotify_min_instances' => 256,
        // Networks outside launchroom's range use Docker's default address
        // pools, which run out at about 30 networks.
        'unmanaged_networks_warning' => 20,
        'unmanaged_networks_critical' => 28,
        // launchroom's environment range (install-wide), percent used.
        'environment_range_warning_percent' => 80,
        // Daemon clock vs dashboard clock; signatures fail beyond 60 s.
        'clock_skew_warning_seconds' => 5,
        'clock_skew_critical_seconds' => 30,
        // "This server is small" (docs/08 §14): the only build server has
        // fewer CPUs or less memory than this. 3500 MB, because a 4 GB
        // machine reports a little less than 4096 MB.
        'small_build_server_cpus' => 2,
        'small_build_server_memory_mb' => 3500,
        // Private, CGNAT and Docker addresses are never shown as where DNS
        // points (docs/05 §5); local dev's fake servers only have those.
        'private_addresses' => (bool) env('LAUNCHROOM_PRIVATE_SERVER_ADDRESSES', env('APP_ENV') === 'local'),
    ],

    'control_plane' => [
        'dashboard_upstream' => env('LAUNCHROOM_DASHBOARD_UPSTREAM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard domain (docs/05 §4.1, D43)
    |--------------------------------------------------------------------------
    |
    | The canonical origin lives in the database (instance_settings.dashboard_url,
    | set by Settings → Dashboard domain) and falls back to APP_URL, the
    | install-time origin; App\Services\Instance\DashboardOrigin is the source
    | of truth and overwrites `app.url` per request, job and launchroom
    | command (boot_url keeps APP_URL as the process started). The mesh's
    | coordination URL stays on APP_URL (Headscale server_url).
    |
    | transition_days: how long the previous origin keeps answering the
    | public API, CLI, GitHub webhooks and the installer after a switch
    | (launchroomd's endpoints keep answering there for good: it is every
    | server's fallback).
    |
    | probe_address: a fixed address the HTTPS test request may connect to
    | when the server's public address isn't reachable from inside its own
    | network (NAT without hairpinning). The control-plane compose file sets
    | host.docker.internal (host-gateway); never user input.
    |
    */

    'dashboard' => [
        'boot_url' => null,
        'applied_url' => null,
        'transition_days' => (int) env('LAUNCHROOM_DASHBOARD_TRANSITION_DAYS', 30),
        'probe_address' => env('LAUNCHROOM_DASHBOARD_PROBE_ADDRESS'),
        'probe_timeout_seconds' => 5,
        // A successful test request counts this long for "Switch".
        'probe_fresh_seconds' => 600,
    ],

    'install' => [
        'scripts_path' => env('LAUNCHROOM_INSTALL_SCRIPTS_PATH', '/var/www/install'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mesh (docs/05 §2)
    |--------------------------------------------------------------------------
    |
    | headscale_url: how the dashboard reaches the embedded Headscale's API on
    | the control-plane network (deploy/control-plane/compose.yml sets
    | http://headscale:8080). Unset: no embedded Headscale (only "bring your
    | own Tailscale" is offered).
    | login_server: the URL servers' mesh clients log in to: the dashboard's
    | public origin (the dashboard's FrankenPHP forwards Headscale's control
    | and DERP paths). Dev: http://headscale.
    | bootstrap_key_path: an API key the installer creates when Headscale
    | first starts; on enable the dashboard replaces it with its own
    | (envelope-encrypted) key and expires it.
    | auto_enable: switch the mesh on when a second server joins.
    |
    */

    'mesh' => [
        'headscale_url' => env('LAUNCHROOM_HEADSCALE_URL'),
        'login_server' => env('LAUNCHROOM_MESH_LOGIN_SERVER'),
        'bootstrap_key_path' => env('LAUNCHROOM_HEADSCALE_BOOTSTRAP_KEY_PATH', '/data/keys/headscale-api-key'),
        // Read-only mount of Headscale's /var/lib/headscale: its keys and
        // database join the control-plane key bundle (docs/14 §9.6). Unset: not backed up.
        'headscale_state_path' => env('LAUNCHROOM_HEADSCALE_STATE_PATH'),
        'headscale_user' => 'launchroom',
        'auto_enable' => (bool) env('LAUNCHROOM_MESH_AUTO_ENABLE', true),
        // Single-use pre-auth keys; refreshed while a server has not joined.
        'pre_auth_key_ttl_minutes' => 60,
        'pre_auth_key_refresh_minutes' => 10,
        // The dashboard's API key is rotated when it has less than 30 days left.
        'api_key_ttl_days' => 180,
        'api_key_rotate_days' => 30,
        // Port of the registry on the control plane's mesh IP (launchroomd forwarder).
        'registry_port' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registry tokens (docs/05 §6, docs/09 R24)
    |--------------------------------------------------------------------------
    |
    | The dashboard is Zot's Docker token-auth server. Tokens are ES256 JWTs
    | signed with a key in its own file (not the CP signing key, not APP_KEY);
    | Zot verifies them with the public key file. Created by
    | `php artisan launchroom:keys:generate` (never overwritten).
    |
    */

    'registry' => [
        'token_key_path' => env('LAUNCHROOM_REGISTRY_TOKEN_KEY_PATH') ?: storage_path('keys/registry-token.es256.pem'),
        'token_public_key_path' => env('LAUNCHROOM_REGISTRY_TOKEN_PUBLIC_KEY_PATH') ?: storage_path('keys/registry-public/registry-token.pub.pem'),
        // Zot's `http.auth.bearer.service`; tokens are issued for this audience.
        'service' => env('LAUNCHROOM_REGISTRY_SERVICE', 'launchroom-registry'),
        'issuer' => 'launchroom',
        // Short-lived by design (docs/09 R24); capped at 300 s in code.
        'token_ttl_seconds' => 300,
        // Zot's config.json as the dashboard container sees it (docs/14 §9.11):
        // `launchroom:registry:config --write` renders it (local or S3 storage)
        // and `./launchroom registry apply` restarts Zot. Unset: not writable
        // from here (dev, where Zot reads the repo's dev/zot/config.json).
        'zot_config_path' => env('LAUNCHROOM_ZOT_CONFIG_PATH') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP to user-influenced URLs (docs/09-security.md R5)
    |--------------------------------------------------------------------------
    */

    'safe_http' => [
        'allowed_schemes' => ['https'],
        'connect_timeout' => 5,
        'timeout' => 15,
        'max_response_bytes' => 10 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub integration (docs/06 §2, docs/09 R17–R20)
    |--------------------------------------------------------------------------
    */

    'github' => [
        // Default GitHub host; GitHub Enterprise Server URLs are entered per app.
        'web_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',

        // Single-use, team-bound manifest state (docs/09 R21-style lifetime).
        'manifest_state_ttl_minutes' => 60,

        // App JWT lifetime; GitHub's maximum is 10 minutes.
        'jwt_ttl_seconds' => 540,

        // Installation tokens live 60 minutes; refresh this long before expiry.
        'token_refresh_margin_seconds' => 300,

        // Warn when GitHub's Date header and our clock differ by more than this.
        'max_clock_skew_seconds' => 30,

        // Polling fallback (docs/06 §2): apps without a verified webhook
        // delivery in this window get their tracked refs polled every minute.
        'webhook_stale_after_seconds' => 900,

        // GitHub caps webhook payloads at 25 MB.
        'max_webhook_bytes' => 25 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment networks (docs/05 §1)
    |--------------------------------------------------------------------------
    |
    | One Docker bridge per environment, with an explicit subnet carved out of
    | this range (unique per install), so Docker's default pool never runs out.
    |
    */

    'networks' => [
        'environment_range' => env('LAUNCHROOM_ENVIRONMENT_RANGE', '10.231.0.0/16'),
        'environment_prefix' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Workload images (docs/04 §5 "Mirrors", docs/05 §6)
    |--------------------------------------------------------------------------
    |
    | Workloads only run digest-pinned images from the local registry. Upstream
    | images (image services, database engines) are pulled through Zot's
    | mirror: `<mirror_prefix>/<upstream registry>/<repository>@sha256:…`,
    | e.g. 127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:….
    |
    */

    'domains' => [
        // Local dev serves generated domains on *.localhost (docs/05 §5, docs/11).
        'localhost' => (bool) env('LAUNCHROOM_LOCALHOST_DOMAINS', env('APP_ENV') === 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Edge ingress (docs/05 §4–5)
    |--------------------------------------------------------------------------
    |
    | caddy_modules: whether the edge Caddy image (daemon.system_images.caddy)
    | is launchroom's build with the storage and DNS modules
    | (ghcr.io/vantezzen/launchroom-caddy, deploy/images/caddy). Without it,
    | NodeStates never ask for shared storage or DNS-01 (stock Caddy would
    | refuse the config). Defaults to "the image name says launchroom-caddy".
    |
    | dns_providers: the DNS-01 providers (App\Enums\DnsProvider values,
    | comma-separated) the configured image has. Empty = every provider of
    | this release's image (deploy/images/caddy); set it when running an
    | older launchroom-caddy build (before D47 it had `cloudflare,hetzner`).
    |
    | cert_storage: shared certificate storage of every edge (Redis protocol).
    | Automatic with two or more edges and the mesh on: the control plane's
    | Valkey, served by its launchroomd on `<mesh IP>:6379` to the edges only,
    | with a dedicated ACL user (`lr-caddy`, keys `lr-caddy`/`lr-caddy/*`)
    | whose generated password is envelope-encrypted (App\Services\Ingress\
    | CertStorage). `address` + `password` override it (an external Redis).
    | Otherwise each edge keeps Caddy's local file storage (fine for one edge).
    |
    | hsts_max_age: Strict-Transport-Security for custom domains once their
    | certificate is issued (no includeSubDomains, no preload).
    |
    */

    'ingress' => [
        'caddy_modules' => (bool) env('LAUNCHROOM_CADDY_MODULES', str_contains((string) env('LAUNCHROOM_CADDY_IMAGE', ''), 'launchroom-')),
        'dns_providers' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('LAUNCHROOM_CADDY_DNS_PROVIDERS', ''))))),
        'cert_storage' => [
            'address' => env('LAUNCHROOM_CERT_STORAGE_ADDRESS'),
            'username' => env('LAUNCHROOM_CERT_STORAGE_USERNAME', 'lr-caddy'),
            'password' => env('LAUNCHROOM_CERT_STORAGE_PASSWORD'),
            'key_prefix' => env('LAUNCHROOM_CERT_STORAGE_KEY_PREFIX', 'lr-caddy'),
        ],
        'hsts_max_age' => 31536000,
    ],

    /*
    |--------------------------------------------------------------------------
    | User docs (docs/21-website.md)
    |--------------------------------------------------------------------------
    |
    | Base URL of the public docs every "Learn more" link points at
    | (App\Support\DocsUrl, the `docsUrl` Inertia prop). Pages are addressed
    | by their path below it ("servers/high-availability-ingress").
    |
    */

    'docs_url' => env('LAUNCHROOM_DOCS_URL', 'https://launchroom.vantezzen.io/docs'),

    'images' => [
        'mirror_prefix' => env('LAUNCHROOM_IMAGE_MIRROR_PREFIX', '127.0.0.1:5000/mirror'),
        'apps_prefix' => env('LAUNCHROOM_IMAGE_APPS_PREFIX', '127.0.0.1:5000/apps'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database engines (docs/04 §10)
    |--------------------------------------------------------------------------
    |
    | Upstream images per major version, always pinned by (multi-arch index)
    | digest. Upgrading a digest is a deliberate change here.
    |
    */

    'database_engines' => [
        'postgres' => ['images' => [
            '18' => 'docker.io/library/postgres@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873', // 18-alpine
            '17' => 'docker.io/library/postgres@sha256:b0f9560a2de083e2cc7382e75f808c7381a32852a7ec49117deedb300e552b24', // 17-alpine
            '16' => 'docker.io/library/postgres@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea', // 16-alpine
        ]],
        'mysql' => ['images' => [
            '8.4' => 'docker.io/library/mysql@sha256:24989179f58856db763f418010c1169fecc944e3ec1ea7e921572d83eb070f70', // 8.4
        ]],
        'redis' => ['images' => [
            '7' => 'docker.io/library/redis@sha256:858f009f9709ce576febc734aa78b8f6d624b82571f9ddb6bda4377c833b3499', // 7-alpine
        ]],
        'valkey' => ['images' => [
            '8' => 'docker.io/valkey/valkey@sha256:081c2f5cb575efc901aa80ff9cdbd1ec6a301682fd35e1ebb4b0990a4a4a8507', // 8-alpine
        ]],
        // D47. 8.0 refuses Linux 6.19+ (SERVER-121912); 7.0 runs there.
        'mongodb' => ['images' => [
            '8.0' => 'docker.io/library/mongo@sha256:4a4ab5d4834207389e0d7270fac988e21ffedccecba77991a552f755df229907', // 8.0.34
            '7.0' => 'docker.io/library/mongo@sha256:c53852c00c23ca9f23fc39690350ffa9a80e3642fe8f96879726d8da0abe80a7', // 7.0.45
        ]],
        // D47. LTS releases only.
        'clickhouse' => ['images' => [
            '26.8' => 'docker.io/clickhouse/clickhouse-server@sha256:81d3a40da04638ce3db09772fa9f98dca34289863c0d0b8a668b33d5b8bf45a6', // 26.8.31.2-alpine
            '26.3' => 'docker.io/clickhouse/clickhouse-server@sha256:813a5f7edfec0a8bfcfc1810b34f944428565232d5242de72aa1891c50c89ff9', // 26.3.56.2-alpine
        ]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling and deployments (docs/02 §2, docs/04 §6–8)
    |--------------------------------------------------------------------------
    */

    'scheduling' => [
        // Memory limits may add up to this multiple of a server's memory.
        'memory_overcommit' => (float) env('LAUNCHROOM_MEMORY_OVERCOMMIT', 1.5),
        // CPU limits may add up to this multiple of a server's cores (limits
        // are caps, not reservations, so this is generous).
        'cpu_overcommit' => (float) env('LAUNCHROOM_CPU_OVERCOMMIT', 4.0),
        // Memory kept free on every server (launchroomd, Caddy, Tailscale,
        // BuildKit), plus the control plane's own stack on its server.
        'reserved_memory_mb' => (int) env('LAUNCHROOM_RESERVED_MEMORY_MB', 256),
        'control_plane_reserved_memory_mb' => (int) env('LAUNCHROOM_CONTROL_PLANE_RESERVED_MEMORY_MB', 768),
        // Rescheduling (docs/02 §6): a server unseen this long is dead for
        // scheduling and its stateless replicas move to healthy servers.
        // Liveness runs every 15 s, so replicas move 90–105 s after the last
        // contact. Never while the dashboard itself has been watching for
        // less than this long (a restarted control plane first waits for
        // every server to check in again).
        'node_dead_after_seconds' => (int) env('LAUNCHROOM_NODE_DEAD_AFTER', 90),
    ],

    'deployments' => [
        // Waiting for edge servers to load new routes before going active.
        'routing_timeout_seconds' => 120,
        // Waiting for a stateful service's old container to stop.
        'stop_timeout_seconds' => 120,
        // Release and seed commands (run_once tasks, docs/04 §13).
        'release_timeout_seconds' => (int) env('LAUNCHROOM_RELEASE_TIMEOUT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Preview environments (docs/06 §5, docs/05 §4)
    |--------------------------------------------------------------------------
    |
    | auth_url: the forward-auth endpoint edge Caddies ask for protected
    | previews. Default: http://<dashboard_upstream>/auth/preview on the
    | control-plane server, else APP_URL/auth/preview. Daemons only accept
    | their own control-plane URL's origin or an allowed local upstream
    | (docs/03 §8). Dev: http://dashboard:8000/auth/preview.
    |
    */

    'previews' => [
        'auth_url' => env('LAUNCHROOM_PREVIEW_AUTH_URL'),
        // Preview replicas get at most this many CPUs (docs/06 §5 resource caps; 1 replica).
        'max_cpus' => (float) env('LAUNCHROOM_PREVIEW_MAX_CPUS', 0.5),
        // Host-only cookie on the preview host (never the dashboard's).
        'cookie' => '__Host-lr-preview',
        'bypass_header' => 'X-Launchroom-Bypass',
        // The public HTTPS port of the edges (dev: 8443). Exchange tokens are
        // only ever sent to preview hosts on this port.
        'https_port' => (int) env('LAUNCHROOM_PREVIEW_HTTPS_PORT', 443),
        // Single-use token swapped for the cookie after the dashboard login.
        'exchange_ttl_seconds' => 120,
        'session_cookie_hours' => 12,
        'share_cookie_hours' => 24,
        'share_link_max_days' => 30,
        // Membership / share-link revocation checks are cached this long.
        'access_cache_seconds' => 60,
        // Default team limit for "clone from another database" (compressed backup size).
        'clone_limit_mb' => (int) env('LAUNCHROOM_PREVIEW_CLONE_LIMIT_MB', 1024),
        // A clone source's latest backup older than this is still used, but flagged.
        'clone_stale_hours' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Builds and plan previews (docs/04 §5, §12–13)
    |--------------------------------------------------------------------------
    */

    'builds' => [
        // Railpack version pinned for new services; must equal the daemon's linked one.
        'railpack_version' => env('LAUNCHROOM_RAILPACK_VERSION', '0.42.0'),
        'timeout_seconds' => (int) env('LAUNCHROOM_BUILD_TIMEOUT', 7200),
        'plan_timeout_seconds' => 300,
        // Lease-expiry retries; a `transient` failure additionally gets one new task.
        'max_attempts' => 2,
        // A build pinned to its sticky server (cache locality) that no server
        // leased within this time (server offline or busy) is released to
        // every build server of the platform (docs/04 §5).
        'pin_release_seconds' => (int) env('LAUNCHROOM_BUILD_PIN_RELEASE', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generate a Dockerfile (docs/20, D48)
    |--------------------------------------------------------------------------
    |
    | Opt-in per instance (Settings → Dockerfile generation) and per team; the
    | provider, model and API key live in `ai_provider_settings`, not here.
    | `model` picks the implementation: `anthropic` (the Claude API through
    | the official SDK) or `stub` (answers from `stub_response`, a JSON file;
    | only in local/testing, refused elsewhere) for the dev stack.
    | Prices are USD per million tokens (input, output) for the cost note.
    |
    */

    'dockerfile_generation' => [
        'model' => env('LAUNCHROOM_DOCKERFILE_MODEL', 'anthropic'),
        'stub_response' => env('LAUNCHROOM_DOCKERFILE_STUB_RESPONSE'),
        'default_model' => 'claude-opus-5-5',
        'suggested_models' => ['claude-opus-5-5', 'claude-sonnet-5'],
        'prices' => [
            'claude-opus-5-5' => [4.0, 20.0],
            'claude-sonnet-5' => [2.0, 10.0],
        ],
        'anthropic_url' => 'https://api.anthropic.com',
        'max_tokens' => 16000,
        'effort' => 'high',
        'connect_timeout_seconds' => 10,
        'timeout_seconds' => 300,
        // Repair iterations after the first draft (docs/20 §5: N = 2).
        'repairs' => 2,
        'probe_seconds' => 90,
        'log_tail_lines' => 80,
        'starts_per_team_per_hour' => 10,
        'prune_after_days' => 30,
        // Sweep: a step without progress for this long fails the generation.
        'step_timeout_seconds' => [
            'reading' => 600,
            'drafting' => 900,
            'building' => 2400,
            'starting' => 900,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public DNS checks (docs/05 §5, docs/08 §3.1)
    |--------------------------------------------------------------------------
    |
    | The platform domain and custom domains are verified against public
    | recursive resolvers (UDP, short timeouts), not the container's resolver.
    | Custom domains are re-checked by `launchroom:domains:verify`: pending
    | ones every few minutes, verified ones a few times a day.
    |
    */

    'dns' => [
        'resolvers' => array_values(array_filter(explode(',', (string) env('LAUNCHROOM_DNS_RESOLVERS', '1.1.1.1,8.8.8.8')))),
        'timeout_seconds' => 2,
        'recheck_pending_minutes' => 5,
        'recheck_verified_hours' => 6,
        // HTTP check through the proxy/CDN in front of a domain (docs/05 §5);
        // off: only the A/AAAA comparison.
        'reachability' => (bool) env('LAUNCHROOM_DNS_HTTP_CHECK', true),
        'reachability_connect_timeout' => 3,
        'reachability_timeout' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime in the browser (docs/08 §8)
    |--------------------------------------------------------------------------
    |
    | Public Reverb client settings, shared with the page at runtime (the
    | production image is built without VITE_REVERB_*). Empty values mean the
    | dashboard's own origin: production proxies /app/* to Reverb.
    |
    */

    'realtime' => [
        'host' => env('LAUNCHROOM_REALTIME_HOST'),
        'port' => env('LAUNCHROOM_REALTIME_PORT'),
        'scheme' => env('LAUNCHROOM_REALTIME_SCHEME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Git sources (docs/04 §13)
    |--------------------------------------------------------------------------
    |
    | Plain smart-HTTP git remotes (no GitHub App) are a local-dev facility
    | for the `git-fixtures` server. Daemons accept `http://` only for hosts in
    | their own LR_BUILD_INSECURE_GIT_HOSTS; this list mirrors that for validation.
    |
    */

    'git' => [
        'plain_sources' => (bool) env('LAUNCHROOM_PLAIN_GIT_SOURCES', env('APP_ENV') === 'local'),
        'insecure_hosts' => array_values(array_filter(explode(',', (string) env('LAUNCHROOM_INSECURE_GIT_HOSTS', env('APP_ENV') === 'local' ? 'git-fixtures,forgejo' : '')))),
        'head_timeout_seconds' => 10,

        // GitLab / Gitea / Forgejo / plain git servers (docs/19).
        'gitlab_url' => 'https://gitlab.com',
        // Where GitLab/Gitea/Forgejo deliver webhooks, when they reach the
        // dashboard under another address than APP_URL (dev: the compose name).
        'webhook_base_url' => env('LAUNCHROOM_GIT_WEBHOOK_URL'),
        // Pending OAuth authorizations (state + PKCE verifier) expire after this.
        'oauth_state_ttl_minutes' => 15,
        // OAuth access tokens are refreshed this long before they expire.
        'token_refresh_margin_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Registry lookups from the dashboard (docs/04 §11, docs/05 §6)
    |--------------------------------------------------------------------------
    |
    | Tag → digest resolution for image services asks Zot's pull-through
    | mirror with a dashboard-issued pull token.
    |
    */

    'registry_lookup' => [
        'url' => env('LAUNCHROOM_REGISTRY_URL', 'http://zot:5000'),
        'timeout_seconds' => 60,
        'cache_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups (docs/14)
    |--------------------------------------------------------------------------
    |
    | Database backups run on the daemon that hosts the database; the
    | control-plane backup runs in this container (restic + pg_dump ship in
    | the dashboard image). Recovery passphrases are stretched with Argon2id;
    | the limits are stored with every bundle so they can be raised later.
    |
    */

    'backups' => [
        'restic_binary' => env('LAUNCHROOM_RESTIC_BINARY', '/usr/local/bin/restic'),
        'restic_cache_dir' => env('LAUNCHROOM_RESTIC_CACHE_DIR') ?: storage_path('app/restic-cache'),
        // The client major must match the server (Postgres 18, PGDG packages).
        'pg_dump_binary' => env('LAUNCHROOM_PG_DUMP_BINARY', '/usr/lib/postgresql/18/bin/pg_dump'),
        'pg_restore_binary' => env('LAUNCHROOM_PG_RESTORE_BINARY', '/usr/lib/postgresql/18/bin/pg_restore'),
        // Hard deadline of one backup/restore task (daemon) or job (dashboard).
        'task_timeout_seconds' => (int) env('LAUNCHROOM_BACKUP_TIMEOUT', 6 * 3600),
        // "Last backup" turns stale after this long without a success.
        'stale_after_hours' => 36,
        // Snapshot listings from the repository are cached this long.
        'snapshot_cache_seconds' => 600,
        // A restore-as-new waits this long for its new service to run (it
        // may stay staged until someone deploys the environment).
        'restore_target_wait_minutes' => 24 * 60,
        // Restoring into a database stops its dependents first; the restore
        // starts once they are down, or after this long regardless.
        'restore_hold_wait_seconds' => 120,
        // Point-in-time recovery for Postgres (docs/14 §10).
        'point_in_time' => [
            // WAL that has not reached the destination for this long alerts.
            'lag_alert_minutes' => 15,
        ],
        // Automatic restore verification (docs/14 §10.4).
        'verification' => [
            'every_days' => 7,
            'timeout_seconds' => 4 * 3600,
            // Limits of the throwaway database (no network, lower CPU weight).
            'cpus' => 1.0,
            'memory_mb' => 1024,
            'pids' => 512,
        ],
        // Final backups before a deletion (docs/14 §9.11) that haven't
        // finished after this long block the deletion (retry or override).
        'final_backup_timeout_minutes' => (int) env('LAUNCHROOM_FINAL_BACKUP_TIMEOUT_MINUTES', 6 * 60 + 30),
        'recovery_kdf' => [
            'opslimit' => (int) env('LAUNCHROOM_RECOVERY_KDF_OPSLIMIT', SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE),
            'memlimit' => (int) env('LAUNCHROOM_RECOVERY_KDF_MEMLIMIT', SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Control-plane restore (`php artisan launchroom:restore`, docs/14 §5.2)
    |--------------------------------------------------------------------------
    |
    | The installer (`install.sh --restore`) passes secrets through the
    | environment (never argv); they can also come from a recovery kit file
    | or be typed at the prompt.
    |
    */

    'restore' => [
        'endpoint' => env('LR_RESTORE_ENDPOINT'),
        'bucket' => env('LR_RESTORE_BUCKET'),
        'prefix' => env('LR_RESTORE_PREFIX'),
        'region' => env('LR_RESTORE_REGION'),
        'access_key_id' => env('LR_RESTORE_ACCESS_KEY_ID'),
        'secret_access_key' => env('LR_RESTORE_SECRET_ACCESS_KEY'),
        'repository_password' => env('LR_RESTORE_REPOSITORY_PASSWORD'),
        'passphrase' => env('LR_RECOVERY_PASSPHRASE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Move to a new server (docs/14 §13)
    |--------------------------------------------------------------------------
    |
    | token / hostname: `install.sh --move` passes the move token and the
    | host's name to `launchroom:move:receive` by environment (never argv).
    |
    */

    'moves' => [
        'token' => env('LR_MOVE_TOKEN'),
        'hostname' => env('LR_MOVE_HOSTNAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protocol schemas (protocol/README.md)
    |--------------------------------------------------------------------------
    |
    | Every rendered NodeState is validated against the Go-generated JSON
    | Schemas before it is signed and stored (App\Services\NodeStates\NodeStateValidator).
    | The production image ships them next to the app (/var/www/protocol);
    | dev mounts ../protocol there.
    |
    */

    'protocol' => [
        'schema_path' => env('LAUNCHROOM_PROTOCOL_SCHEMA_PATH') ?: dirname(base_path()).'/protocol/schema/v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Volume lifecycle (docs/05 §1.1, docs/14 §1)
    |--------------------------------------------------------------------------
    |
    | Volumes of deleted environments, services, projects and previews are
    | retained this long, then deleted on their server through an explicit
    | NodeState `deleteVolumes` request. Volumes whose service has backups
    | are only deleted after a successful final backup or a recorded
    | confirmation by a person. 0 = delete at the next sweep.
    |
    */

    'volumes' => [
        'retention_hours' => (int) env('LAUNCHROOM_VOLUME_RETENTION_HOURS', 7 * 24),
        'preview_retention_hours' => (int) env('LAUNCHROOM_PREVIEW_VOLUME_RETENTION_HOURS', 7 * 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs: search and runtime log retention (docs/08 §12)
    |--------------------------------------------------------------------------
    |
    | Runtime log retention is opt-in per environment (period and size cap,
    | chosen from these options). Without it, runtime lines from live tails
    | are kept `tail_retention_hours`. Build and deploy logs are kept as long
    | as their deployments.
    |
    */

    'logs' => [
        'retention_days' => [1, 3, 7, 14, 30],
        'max_mb' => [256, 1024, 5120, 10240],
        'default_retention_days' => 7,
        'default_max_mb' => 1024,
        'tail_retention_hours' => (int) env('LAUNCHROOM_LOG_TAIL_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Interactive sessions: Console and Data tabs (docs/03 §11, docs/09 R33)
    |--------------------------------------------------------------------------
    |
    | `enabled` is the instance-wide kill switch: off refuses new sessions and
    | cancels open ones on their next heartbeat. Servers have their own local
    | switch (LR_INTERACTIVE_SESSIONS in the server's .env).
    |
    */

    'sessions' => [
        'enabled' => (bool) env('LAUNCHROOM_INTERACTIVE_SESSIONS', true),
        'ticket_seconds' => 60,
        'idle_minutes' => (int) env('LAUNCHROOM_SESSION_IDLE_MINUTES', 15),
        'max_minutes' => min(240, max(5, (int) env('LAUNCHROOM_SESSION_MAX_MINUTES', 60))),
        'max_open_per_user' => 3,
        'command_ttl_seconds' => 60,
        'history_limit' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data import and export (D55, docs/14 §12)
    |--------------------------------------------------------------------------
    */

    'transfers' => [
        // Chunks of uploads and exports, encrypted at rest (docs/14 §12.1).
        'path' => env('LAUNCHROOM_TRANSFERS_PATH') ?: storage_path('app/private/transfers'),
        'chunk_bytes' => 8 * 1024 * 1024,
        // Largest file accepted (100 GiB).
        'max_bytes' => (int) env('LAUNCHROOM_TRANSFERS_MAX_BYTES', 100 * 1024 * 1024 * 1024),
        // Free space kept on the control-plane disk beyond a declared upload.
        'free_space_margin_bytes' => 1024 * 1024 * 1024,
        'upload_ttl_hours' => 24,
        'download_ttl_minutes' => 60,
        'link_ttl_seconds' => 300,
        'task_timeout_seconds' => 21600,
        'inspect_timeout_seconds' => 600,
        'inspection_ttl_seconds' => 600,
        // The throwaway container of database imports.
        'resources' => ['cpus' => 1.0, 'memory_mb' => 1024, 'pids' => 512],
    ],

];
