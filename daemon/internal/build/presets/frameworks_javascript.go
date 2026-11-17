package presets

import (
	"encoding/json"
	"fmt"
	"maps"
	"path"
	"regexp"
	"slices"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/build/nextadapter"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// javascriptPresets are the JavaScript framework presets in matching order.
// All() puts them before staticSPA and nodeServer, the catch-alls.
func javascriptPresets() []Preset {
	return []Preset{nextjs{}, nuxt{}, svelteKit{}, astro{}, reactRouter{}, tanstackStart{}, angularSSR{}, gatsby{}}
}

// NextAdapterPackage is the adapter injected through NEXT_ADAPTER_PATH
// (vendored into the daemon, see internal/build/nextadapter).
const NextAdapterPackage = "@vantezzen/launchroom-next-adapter"

// NextAdapterDecision is the id of the Next ≥ 15.4 adapter decision. The
// build pipeline adds LAUNCHROOM_DEPLOYMENT_ID and parses the adapter's
// summary line when it is applied.
const NextAdapterDecision = "deployment-adapter"

// NextDeploymentIDDecision is the id of the decision for Next < 15.4 (no
// adapter): the build pipeline sets NEXT_DEPLOYMENT_ID to the deployment id
// for `next build` when it is applied, as it sets LAUNCHROOM_DEPLOYMENT_ID
// for the adapter.
const NextDeploymentIDDecision = "deployment-id"

// ForwardedHTTPSDecision is the id of the Node preload decision (OD11).
const ForwardedHTTPSDecision = "forwarded-https"

// ForwardedPreloadPath is where the Node preload lives in the runtime image.
const ForwardedPreloadPath = "/opt/launchroom/node/forwarded.mjs"

// forwardedPreload marks a request as TLS for the app when launchroom's
// edge received it over HTTPS. The edge overwrites X-Forwarded-Proto, so
// the header can't be forged from outside (the trust argument of D85). The
// original value is restored per request: the edge reuses one upstream
// connection for requests of different clients.
const forwardedPreload = `// launchroom: Node treats a request as HTTPS when launchroom's edge received it
// over HTTPS (X-Forwarded-Proto: https, set by the edge, never by the client).
// Express req.secure, Astro's origin check and React Router's request.url
// then see https. Remove the "forwarded-https" decision to turn this off.
import http from 'node:http';

const original = Symbol.for('launchroom.encrypted');
const emit = http.Server.prototype.emit;

http.Server.prototype.emit = function (event, req, ...rest) {
  if (event === 'request') {
    try {
      const socket = req.socket;
      if (socket && !(original in socket)) socket[original] = socket.encrypted === true;
      if (socket) socket.encrypted = socket[original] || req.headers['x-forwarded-proto'] === 'https';
    } catch {
      // Never break a request over this.
    }
  }
  return emit.call(this, event, req, ...rest);
};
`

// forwardedHTTPSDecision is the Node preload for servers that build URLs
// from the socket (Express, Astro node, React Router, Remix, srvx). It is
// skipped for Bun, and for Node versions without --import.
func forwardedHTTPSDecision(i Info) (Decision, bool) {
	if _, bun := i.Packages["bun"]; bun {
		return Decision{}, false
	}
	if v, ok := i.Packages["node"]; ok {
		if ma, mi, ok := majorMinor(v); ok && (ma < 18 || (ma == 18 && mi < 18)) {
			return Decision{}, false
		}
	}
	return Decision{
		ID: ForwardedHTTPSDecision, Title: "Requests over HTTPS look like HTTPS",
		Detail: "NODE_OPTIONS=--import=" + ForwardedPreloadPath + " marks a request as secure when it reached launchroom over HTTPS, so cookies, redirects and origin checks use https. A NODE_OPTIONS variable you set replaces it.",
		Effect: Effect{Plan: PlanPatch{
			DeployVariables: map[string]string{"NODE_OPTIONS": "--import=" + ForwardedPreloadPath},
			DeployFiles:     map[string]string{ForwardedPreloadPath: forwardedPreload},
		}},
	}, true
}

// reSafePath is the subset of HTTP paths a preset uses as a health path.
var reSafePath = regexp.MustCompile(`^/[A-Za-z0-9._~%/-]*$`)

// basePath reads a base path option ("basePath", "base", …) from a config
// file's text. Only a string literal counts; "/" and unusual values give "".
func basePath(cfg, option string) string {
	re := regexp.MustCompile(`\b` + regexp.QuoteMeta(option) + `\s*:\s*['"` + "`" + `](/[^'"` + "`" + `\s]*)['"` + "`" + `]`)
	m := re.FindStringSubmatch(cfg)
	if m == nil {
		return ""
	}
	p := strings.TrimRight(m[1], "/")
	if p == "" || !reSafePath.MatchString(p) || len(p) > 200 {
		return ""
	}
	return p
}

// basePathDecision checks the health path under the app's base path, where
// "/" answers 404.
func basePathDecision(file, option, base string) []Decision {
	if base == "" {
		return nil
	}
	return []Decision{{
		ID: "base-path-health", Title: "Health check under " + base,
		Detail: file + " sets " + option + " to " + base + ", so the app answers there and not on /.",
		Effect: Effect{HealthPath: base},
	}}
}

// authJS covers Auth.js (next-auth v5, @auth/*) and NextAuth v4: trust the
// host the edge forwards, generate the signing secret and, for v4, point
// NEXTAUTH_URL at the environment's public URL.
func authJS(f Facts) []Decision {
	v, nextAuth := f.PackageJSON.Dep("next-auth")
	authCore := false
	for d := range f.PackageJSON.Deps {
		if strings.HasPrefix(d, "@auth/") {
			authCore = true
		}
	}
	if !nextAuth && !authCore {
		return nil
	}
	if locked := f.JS.Locked["next-auth"]; locked != "" {
		v = locked
	}
	v4 := false
	if ma, _, ok := majorMinor(v); nextAuth && ok && ma < 5 {
		v4 = true
	}
	ds := []Decision{{
		ID: "auth-trust-host", Title: "Auth.js trusts the forwarded host",
		Detail: "AUTH_TRUST_HOST=true: launchroom's edge sets the forwarded host, so sign-in works on every domain and preview.",
		Effect: Effect{RuntimeEnv: map[string]string{"AUTH_TRUST_HOST": "true"}},
	}}
	secret := "AUTH_SECRET"
	if v4 {
		secret = "NEXTAUTH_SECRET"
		ds = append(ds, Decision{
			ID: "nextauth-url", Title: "NEXTAUTH_URL follows the domain",
			Detail: "NEXTAUTH_URL is the environment's public URL, so NextAuth redirects back to it and not to localhost.",
			Effect: Effect{EnvAliases: []state.EnvAlias{{Name: "NEXTAUTH_URL", From: state.AliasPublicURL}}},
		})
	}
	ds = append(ds, Decision{
		ID: "auth-secret", Title: "Generate " + secret,
		Detail: secret + " signs and encrypts sessions. launchroom generates one per environment when you haven't set it.",
		Effect: Effect{GeneratedSecrets: []state.GeneratedSecret{{Name: state.EnvName(secret), Length: 32, Encoding: state.SecretBase64}}},
	})
	return ds
}

type nextjs struct{}

func (nextjs) Name() string { return "nextjs" }

// Version 3: adapter from 15.4 (lockfile version), deployment id below it,
// generated server actions key, Auth.js, health under basePath.
func (nextjs) Version() int { return 3 }

func (nextjs) Match(f Facts, i Info) bool {
	_, ok := f.PackageJSON.Dep("next")
	return ok && i.Metadata["nodeIsSPA"] != "true"
}

func compactConfig(cfg string) string {
	return strings.NewReplacer(" ", "", "\t", "", "\n", "", "\r", "").Replace(cfg)
}

func hasStandalone(cfg string) bool {
	c := compactConfig(cfg)
	return strings.Contains(c, "output:'standalone'") || strings.Contains(c, `output:"standalone"`)
}

func hasExport(cfg string) bool {
	c := compactConfig(cfg)
	return strings.Contains(c, "output:'export'") || strings.Contains(c, `output:"export"`)
}

// nextStandaloneDir is the standalone output relative to the app directory.
// The adapter pins outputFileTracingRoot to the build context root (the app
// directory), so server.js sits directly in it.
const nextStandaloneDir = ".next/standalone"

// nextVersion is the Next version the lockfile resolved, else the
// package.json range ("^15.3.0" may install 15.5).
func nextVersion(f Facts) string {
	if v := f.JS.Locked["next"]; v != "" {
		return v
	}
	v, _ := f.PackageJSON.Dep("next")
	return v
}

// nextAdapterDecision builds the adapter decision, or a reason why it can't apply.
func nextAdapterDecision(f Facts) (*Decision, *Warning) {
	if strings.Contains(f.NextConfig, "adapterPath") {
		return nil, &Warning{Code: "own-next-adapter", Message: "next.config sets adapterPath, which takes precedence over the launchroom adapter; the image keeps the full node_modules unless your adapter produces standalone output."}
	}
	if hasExport(f.NextConfig) {
		return nil, nil
	}
	files, err := nextadapter.Files()
	if err != nil {
		return nil, &Warning{Code: "next-adapter-unavailable", Message: "The vendored launchroom Next.js adapter is damaged (" + err.Error() + "); building without it."}
	}
	return &Decision{
		ID: NextAdapterDecision, Title: "launchroom deployment adapter",
		Detail: "NEXT_ADAPTER_PATH=" + NextAdapterPackage + " " + nextadapter.Version + ": standalone output, deploymentId, public/ and .next/static copied, build manifest; the image contains only " + nextStandaloneDir + ".",
		Effect: Effect{Plan: PlanPatch{
			BuildVariables: map[string]string{
				"NEXT_ADAPTER_PATH":    nextadapter.Entry,
				"LAUNCHROOM_REPO_ROOT": "/app",
				// The CLI below finalizes explicitly (and prints the summary).
				"LAUNCHROOM_NEXT_FINALIZE": "manual",
			},
			BuildFiles:          files,
			AppendBuildCommands: []string{nextadapter.FinalizeCommand},
			StartCommand:        "node " + nextStandaloneDir + "/server.js",
			DeployOnly:          []string{nextStandaloneDir},
		}},
	}, nil
}

func (nextjs) Propose(f Facts, _ Info) Proposal {
	version := nextVersion(f)
	p := Proposal{Framework: "next", Port: 3000, HealthPath: "/"}
	p.Decisions = append(p.Decisions, Decision{
		ID: "bind-all-interfaces", Title: "Listen on all interfaces", Detail: "HOSTNAME=0.0.0.0 (Docker sets HOSTNAME to the container id).",
		Effect: Effect{RuntimeEnv: map[string]string{"HOSTNAME": "0.0.0.0"}},
	}, Decision{
		ID: "telemetry-off", Title: "Disable Next.js telemetry", Detail: "NEXT_TELEMETRY_DISABLED=1 at build and runtime.",
		Effect: Effect{
			RuntimeEnv: map[string]string{"NEXT_TELEMETRY_DISABLED": "1"},
			Plan:       PlanPatch{BuildVariables: map[string]string{"NEXT_TELEMETRY_DISABLED": "1"}},
		},
	})
	// Next reads the adapter from NEXT_ADAPTER_PATH since 15.4.
	var adapter *Decision
	if atLeast(version, 15, 4) {
		var w *Warning
		adapter, w = nextAdapterDecision(f)
		if w != nil {
			p.Warnings = append(p.Warnings, *w)
		}
	} else if atLeast(version, 14, 0) && !hasExport(f.NextConfig) {
		p.Decisions = append(p.Decisions, Decision{
			ID: NextDeploymentIDDecision, Title: "Deployment id",
			Detail: "NEXT_DEPLOYMENT_ID is the deployment at build, so a browser tab from the previous version asks for its own assets.",
		})
	}
	switch {
	case adapter != nil:
		p.Decisions = append(p.Decisions, *adapter)
	case hasStandalone(f.NextConfig):
		p.Decisions = append(p.Decisions, Decision{
			ID: "standalone", Title: "Standalone output", Detail: "Copies public/ and .next/static into .next/standalone and starts node .next/standalone/server.js.",
			Effect: Effect{Plan: PlanPatch{
				AppendBuildCommands: []string{
					"mkdir -p .next/standalone/.next && cp -r .next/static .next/standalone/.next/static && if [ -d public ]; then cp -r public .next/standalone/public; fi",
				},
				StartCommand: "node .next/standalone/server.js",
			}},
		})
		if ma, _, ok := majorMinor(version); ok && ma == 14 {
			if _, sharp := f.PackageJSON.Dep("sharp"); !sharp {
				p.Warnings = append(p.Warnings, Warning{Code: "next-sharp-missing", Message: "Next.js 14 needs the sharp package for image optimization in standalone output. Add sharp to dependencies, or upgrade to Next.js 15.4 or later."})
			}
		}
	case hasExport(f.NextConfig):
	default:
		p.Warnings = append(p.Warnings, Warning{Code: "standalone-off", Message: "Next.js 15.4 and later get a much smaller image automatically. On this version, set output: 'standalone' in next.config and launchroom copies the static assets for you."})
	}
	if !containsStr(f.BuildEnv, "NEXT_SERVER_ACTIONS_ENCRYPTION_KEY") {
		p.Decisions = append(p.Decisions, Decision{
			ID: "server-actions-key", Title: "Generate a server actions key",
			Detail: "NEXT_SERVER_ACTIONS_ENCRYPTION_KEY is generated once for the service and shared by its environments, so server actions keep working after a redeploy and when a build is promoted.",
			Effect: Effect{GeneratedSecrets: []state.GeneratedSecret{{
				Name: "NEXT_SERVER_ACTIONS_ENCRYPTION_KEY", Length: 32, Encoding: state.SecretBase64,
				Scope: state.SecretBuild, Shared: state.ShareService,
			}}},
		})
	}
	p.Decisions = append(p.Decisions, authJS(f)...)
	p.Decisions = append(p.Decisions, basePathDecision("next.config", "basePath", basePath(f.NextConfig, "basePath"))...)
	// The dashboard shows this only for more than one replica (it knows the count).
	p.Warnings = append(p.Warnings, Warning{Code: "isr-cache-warning", Message: "With more than one replica, ISR and revalidateTag need a shared cache handler (e.g. Redis)."})
	return p
}

type nuxt struct{}

func (nuxt) Name() string { return "nuxt" }
func (nuxt) Version() int { return 1 }

func (nuxt) Match(f Facts, i Info) bool {
	_, ok := f.PackageJSON.Dep("nuxt")
	return ok && i.Metadata["nodeIsSPA"] != "true"
}

func (nuxt) Propose(f Facts, _ Info) Proposal {
	p := Proposal{Framework: "nuxt", Port: 3000, HealthPath: "/"}
	p.Decisions = append(p.Decisions, Decision{
		ID: "nuxt-server", Title: "Start the Nitro server",
		Detail: "Starts node .output/server/index.mjs, the server nuxt build writes. It reads PORT and HOST.",
		Effect: Effect{Plan: PlanPatch{StartCommand: "node .output/server/index.mjs"}},
	})
	cfg := f.JS.Configs["nuxt"]
	if m := regexp.MustCompile(`\bpreset\s*:\s*['"]([a-z0-9_-]+)['"]`).FindStringSubmatch(cfg); m != nil && !strings.HasPrefix(m[1], "node") {
		p.Warnings = append(p.Warnings, Warning{Code: "nitro-preset", Message: "nuxt.config sets the Nitro preset " + m[1] + ", which doesn't build a Node server. Remove it or use node-server."})
	}
	p.Decisions = append(p.Decisions, authJS(f)...)
	p.Decisions = append(p.Decisions, basePathDecision("nuxt.config", "app.baseURL", basePath(cfg, "baseURL"))...)
	return p
}

type svelteKit struct{}

func (svelteKit) Name() string { return "sveltekit" }
func (svelteKit) Version() int { return 1 }

func (svelteKit) Match(f Facts, _ Info) bool {
	_, ok := f.PackageJSON.Dep("@sveltejs/kit")
	return ok
}

// svelteAdapter is the adapter svelte.config imports, else the installed one.
func svelteAdapter(f Facts) string {
	if m := regexp.MustCompile(`@sveltejs/adapter-([a-z-]+)`).FindStringSubmatch(f.JS.Configs["svelte"]); m != nil {
		return m[1]
	}
	for _, a := range []string{"node", "auto", "static", "vercel", "netlify", "cloudflare", "cloudflare-workers"} {
		if _, ok := f.PackageJSON.Dep("@sveltejs/adapter-" + a); ok {
			return a
		}
	}
	return ""
}

func (svelteKit) Propose(f Facts, _ Info) Proposal {
	p := Proposal{Framework: "sveltekit", Port: 3000, HealthPath: "/"}
	cfg := f.JS.Configs["svelte"]
	adapter := svelteAdapter(f)
	switch adapter {
	case "node":
		out := "build"
		if m := regexp.MustCompile(`\bout\s*:\s*['"]([A-Za-z0-9._/-]+)['"]`).FindStringSubmatch(cfg); m != nil && !strings.Contains(m[1], "..") {
			out = strings.TrimPrefix(path.Clean(m[1]), "./")
		}
		p.Decisions = append(p.Decisions, Decision{
			ID: "sveltekit-node-server", Title: "Start the adapter-node server",
			Detail: "Starts node " + out + ", the server @sveltejs/adapter-node writes.",
			Effect: Effect{Plan: PlanPatch{StartCommand: "node " + out}},
		})
	case "", "auto":
		// Railpack builds adapter-auto with adapter-node and starts node build.
	case "static":
		p.Warnings = append(p.Warnings, Warning{Code: "sveltekit-adapter-static", Message: "@sveltejs/adapter-static builds files without a server. Switch to @sveltejs/adapter-node, or deploy the build folder as a static site."})
	default:
		p.Warnings = append(p.Warnings, Warning{Code: "sveltekit-adapter-unsupported", Message: "Your app is built for @sveltejs/adapter-" + adapter + ". Switch to @sveltejs/adapter-node or adapter-auto to run it on your server."})
	}
	if adapter == "node" || adapter == "auto" || adapter == "" {
		p.Decisions = append(p.Decisions, Decision{
			ID: "client-address", Title: "Visitor IP from the edge",
			Detail: "ADDRESS_HEADER=X-Forwarded-For and XFF_DEPTH=1, so getClientAddress() returns the visitor's IP and not launchroom's edge.",
			Effect: Effect{RuntimeEnv: map[string]string{"ADDRESS_HEADER": "X-Forwarded-For", "XFF_DEPTH": "1"}},
		})
	}
	p.Decisions = append(p.Decisions, authJS(f)...)
	p.Decisions = append(p.Decisions, basePathDecision("svelte.config", "kit.paths.base", basePath(cfg, "base"))...)
	return p
}

type astro struct{}

func (astro) Name() string { return "astro" }
func (astro) Version() int { return 1 }

func (astro) Match(f Facts, i Info) bool {
	_, ok := f.PackageJSON.Dep("astro")
	return ok && i.Metadata["nodeIsSPA"] != "true"
}

func (astro) Propose(f Facts, i Info) Proposal {
	p := Proposal{Framework: "astro", Port: 3000, HealthPath: "/"}
	cfg := f.JS.Configs["astro"]
	_, node := f.PackageJSON.Dep("@astrojs/node")
	switch {
	case node && regexp.MustCompile(`\bmode\s*:\s*['"]middleware['"]`).MatchString(cfg):
		p.Warnings = append(p.Warnings, Warning{Code: "astro-node-middleware", Message: "@astrojs/node runs in middleware mode, so dist/server/entry.mjs is a handler for your own server. Set mode: 'standalone', or set a start command that runs your server."})
	case node:
		p.Decisions = append(p.Decisions, Decision{
			ID: "astro-node-server", Title: "Start the Astro server",
			Detail: "Starts node ./dist/server/entry.mjs, the standalone server @astrojs/node writes. It reads PORT and HOST.",
			Effect: Effect{Plan: PlanPatch{StartCommand: "node ./dist/server/entry.mjs"}},
		})
	default:
		for _, a := range []string{"vercel", "netlify", "cloudflare"} {
			if _, ok := f.PackageJSON.Dep("@astrojs/" + a); ok {
				p.Warnings = append(p.Warnings, Warning{Code: "astro-adapter-unsupported", Message: "Your app is built for @astrojs/" + a + ". Switch to @astrojs/node (standalone) to run it on your server."})
				break
			}
		}
	}
	if d, ok := forwardedHTTPSDecision(i); ok {
		p.Decisions = append(p.Decisions, d)
	}
	p.Decisions = append(p.Decisions, authJS(f)...)
	p.Decisions = append(p.Decisions, basePathDecision("astro.config", "base", basePath(cfg, "base"))...)
	return p
}

// reactRouter covers React Router 7 framework mode and Remix.
type reactRouter struct{}

func (reactRouter) Name() string { return "react-router" }
func (reactRouter) Version() int { return 1 }

func (reactRouter) Match(f Facts, i Info) bool {
	_, rr := f.PackageJSON.Dep("@react-router/dev")
	_, remix := f.PackageJSON.Dep("@remix-run/dev")
	return (rr || remix) && i.Metadata["nodeIsSPA"] != "true"
}

func (reactRouter) Propose(f Facts, i Info) Proposal {
	framework, serve, bin := "react-router", "@react-router/serve", "react-router-serve"
	if _, rr := f.PackageJSON.Dep("@react-router/dev"); !rr {
		framework, serve, bin = "remix", "@remix-run/serve", "remix-serve"
	}
	p := Proposal{Framework: framework, Port: 3000, HealthPath: "/"}
	if _, hasStart := f.PackageJSON.Scripts["start"]; !hasStart {
		if _, ok := f.PackageJSON.Dep(serve); ok {
			p.Decisions = append(p.Decisions, Decision{
				ID: "serve-build", Title: "Start " + bin,
				Detail: "package.json has no start script, so launchroom starts " + bin + " ./build/server/index.js.",
				Effect: Effect{Plan: PlanPatch{StartCommand: "./node_modules/.bin/" + bin + " ./build/server/index.js"}},
			})
		} else {
			p.Warnings = append(p.Warnings, Warning{Code: "no-start-script", Message: "package.json has no \"start\" script; add one (for example " + bin + " ./build/server/index.js) or set a start command."})
		}
	}
	if d, ok := forwardedHTTPSDecision(i); ok {
		p.Decisions = append(p.Decisions, d)
	}
	p.Decisions = append(p.Decisions, authJS(f)...)
	p.Decisions = append(p.Decisions, basePathDecision("react-router.config", "basename", basePath(f.JS.Configs["react-router"], "basename"))...)
	return p
}

type tanstackStart struct{}

func (tanstackStart) Name() string { return "tanstack-start" }
func (tanstackStart) Version() int { return 1 }

func (tanstackStart) Match(f Facts, i Info) bool {
	for _, d := range []string{"@tanstack/react-start", "@tanstack/solid-start", "@tanstack/start"} {
		if _, ok := f.PackageJSON.Dep(d); ok {
			return i.Metadata["nodeIsSPA"] != "true"
		}
	}
	return false
}

// Propose keeps Railpack's start command (the built server); the preset
// labels the app and adds the preload.
func (tanstackStart) Propose(f Facts, i Info) Proposal {
	p := Proposal{Framework: "tanstack-start", Port: 3000, HealthPath: "/"}
	if d, ok := forwardedHTTPSDecision(i); ok {
		p.Decisions = append(p.Decisions, d)
	}
	p.Decisions = append(p.Decisions, authJS(f)...)
	return p
}

// angularSSR covers Angular 17+ with server-side rendering (@angular/ssr).
// Railpack plans every Angular app as a static site; a server build then
// has no index.html to serve.
type angularSSR struct{}

func (angularSSR) Name() string { return "angular-ssr" }
func (angularSSR) Version() int { return 1 }

func (angularSSR) Match(f Facts, _ Info) bool {
	_, ok := f.PackageJSON.Dep("@angular/ssr")
	return ok && angularServer(f.JS.Configs["angular"]) != ""
}

// angularServer returns the server bundle of the first project that builds
// with SSR (dist/<project>/server/server.mjs), or "".
func angularServer(angularJSON string) string {
	var cfg struct {
		Projects map[string]struct {
			Architect map[string]struct {
				Options struct {
					OutputPath json.RawMessage `json:"outputPath"`
					OutputMode string          `json:"outputMode"`
					SSR        json.RawMessage `json:"ssr"`
				} `json:"options"`
			} `json:"architect"`
		} `json:"projects"`
	}
	if json.Unmarshal([]byte(angularJSON), &cfg) != nil {
		return ""
	}
	reName := regexp.MustCompile(`^[A-Za-z0-9._-]+$`)
	reOut := regexp.MustCompile(`^[A-Za-z0-9._/-]+$`)
	for _, name := range slices.Sorted(maps.Keys(cfg.Projects)) {
		build := cfg.Projects[name].Architect["build"].Options
		ssr := len(build.SSR) > 0 && string(build.SSR) != "false"
		if !reName.MatchString(name) || build.OutputMode == "static" || (!ssr && build.OutputMode != "server") {
			continue
		}
		out := "dist/" + name
		var s string
		var o struct {
			Base string `json:"base"`
		}
		switch {
		case json.Unmarshal(build.OutputPath, &s) == nil && s != "":
			out = s
		case json.Unmarshal(build.OutputPath, &o) == nil && o.Base != "":
			out = o.Base
		}
		out = strings.TrimPrefix(path.Clean(out), "./")
		if !reOut.MatchString(out) || strings.Contains(out, "..") || strings.HasPrefix(out, "/") {
			continue
		}
		return out + "/server/server.mjs"
	}
	return ""
}

// PlanEnv is Railpack configuration a JavaScript preset needs before
// planning (the build pipeline passes it to the Railpack plan; a value the
// user set wins). Angular SSR must not be planned as a static site.
func PlanEnv(f Facts) map[string]string {
	if (angularSSR{}).Match(f, Info{}) {
		return map[string]string{"RAILPACK_NO_SPA": "1"}
	}
	return nil
}

func (angularSSR) Propose(f Facts, i Info) Proposal {
	server := angularServer(f.JS.Configs["angular"])
	p := Proposal{Framework: "angular", Port: 3000, HealthPath: "/"}
	if i.Metadata["nodeIsSPA"] == "true" {
		// The image is a static file server: nothing to start the Node server with.
		p.Port = 8080
		p.Warnings = append(p.Warnings, Warning{Code: "angular-ssr-static", Message: "This Angular app renders on the server, but it was built as a static site, which has no index.html for it. Turn off server-side rendering, or build it with a Dockerfile that runs " + server + "."})
		return p
	}
	p.Decisions = append(p.Decisions, Decision{
		ID: "angular-ssr-server", Title: "Start the Angular server",
		Detail: "Starts node " + server + ", the server ng build writes for server-side rendering. It reads PORT.",
		Effect: Effect{Plan: PlanPatch{StartCommand: "node " + server}},
	})
	if d, ok := forwardedHTTPSDecision(i); ok {
		p.Decisions = append(p.Decisions, d)
	}
	return p
}

type gatsby struct{}

func (gatsby) Name() string { return "gatsby" }
func (gatsby) Version() int { return 1 }

func (gatsby) Match(f Facts, i Info) bool {
	_, ok := f.PackageJSON.Dep("gatsby")
	return ok && i.Metadata["nodeIsSPA"] != "true"
}

// Propose serves the production build: Gatsby starters' start script is
// `gatsby develop`, a development server.
func (gatsby) Propose(f Facts, _ Info) Proposal {
	p := Proposal{Framework: "gatsby", Port: 3000, HealthPath: "/"}
	p.Decisions = append(p.Decisions, Decision{
		ID: "gatsby-serve", Title: "Serve the production build",
		Detail: "Starts gatsby serve on $PORT, which serves public/ from gatsby build. The start script of Gatsby starters runs the development server.",
		Effect: Effect{Plan: PlanPatch{StartCommand: "./node_modules/.bin/gatsby serve --host 0.0.0.0 --port ${PORT:-3000}"}},
	})
	return p
}

// nodeServer covers Express, Fastify, Hono, Koa, Nest and plain Node.
type nodeServer struct{}

func (nodeServer) Name() string { return "node-server" }

// Version 2: forwarded HTTPS preload, Nest start:prod.
func (nodeServer) Version() int { return 2 }

func (nodeServer) Match(f Facts, i Info) bool {
	return f.PackageJSON != nil && (i.Provider == "" || i.Provider == "node")
}

func (nodeServer) Propose(f Facts, i Info) Proposal {
	framework := "node"
	for _, d := range []string{"express", "fastify", "hono", "koa", "@nestjs/core"} {
		if _, ok := f.PackageJSON.Dep(d); ok {
			framework = strings.TrimPrefix(strings.Split(d, "/")[0], "@")
			break
		}
	}
	if framework == "nestjs" {
		framework = "nest"
	}
	p := Proposal{Framework: framework, Port: 3000, HealthPath: "/"}
	p.Decisions = append(p.Decisions, Decision{
		ID: "port-contract", Title: "Listen on $PORT", Detail: "launchroom sets PORT=3000; your server must listen on process.env.PORT.",
		Effect: Effect{Port: 3000},
	})
	if prod, ok := f.PackageJSON.Scripts["start:prod"]; ok && framework == "nest" {
		start := "npm run start:prod"
		if regexp.MustCompile(`^node [A-Za-z0-9._/-]+$`).MatchString(strings.TrimSpace(prod)) {
			start = strings.TrimSpace(prod)
		}
		p.Decisions = append(p.Decisions, Decision{
			ID: "nest-start-prod", Title: "Start the compiled app",
			Detail: "Runs " + start + " instead of nest start, which compiles at every start and needs the development dependencies.",
			Effect: Effect{Plan: PlanPatch{StartCommand: start}},
		})
	}
	if d, ok := forwardedHTTPSDecision(i); ok {
		p.Decisions = append(p.Decisions, d)
	}
	p.Decisions = append(p.Decisions, authJS(f)...)
	for _, l := range f.Listeners {
		p.Warnings = append(p.Warnings, Warning{
			Code:    "hardcoded-port",
			Message: fmt.Sprintf("%s calls listen(%s) without reading process.env.PORT; use process.env.PORT so the platform can route to it.", l.File, l.Port),
		})
		break
	}
	_, hasStart := f.PackageJSON.Scripts["start"]
	if !hasStart && f.PackageJSON.Main == "" && !f.Files["index.js"] && !f.Files["index.ts"] && !f.Files["server.js"] {
		p.Warnings = append(p.Warnings, Warning{Code: "no-start-script", Message: "package.json has no \"start\" script or \"main\"; add one or set a start command."})
	}
	return p
}
