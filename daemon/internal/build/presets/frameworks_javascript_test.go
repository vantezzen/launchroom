package presets

import (
	"slices"
	"strings"
	"testing"
	"testing/fstest"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func mapRepo(files map[string]string) Facts {
	fsys := fstest.MapFS{}
	for name, data := range files {
		fsys[name] = &fstest.MapFile{Data: []byte(data)}
	}
	return Gather(fsys, nil)
}

func resolveJS(t *testing.T, f Facts, info Info, want string) *Result {
	t.Helper()
	r, ok := Resolve(f, info, "", nil)
	if !ok || r == nil {
		t.Fatalf("no preset matched")
	}
	if string(r.Applied.Name) != want {
		t.Fatalf("preset = %s, want %s", r.Applied.Name, want)
	}
	return r
}

func hasDecision(r *Result, id string) bool { return slices.Contains(decisionIDs(r), id) }

var nodeInfo = Info{Provider: "node", Packages: map[string]string{"node": "22.11.0"}}

func TestNextLockfileVersionAuthAndBasePath(t *testing.T) {
	f := repo(t, "nextjs-15-lock")
	if f.JS.Locked["next"] != "15.5.2" || f.JS.Locked["next-auth"] != "4.24.11" {
		t.Fatalf("locked = %v", f.JS.Locked)
	}
	r := resolveJS(t, f, nodeInfo, "nextjs")
	// ^15.3.0 resolved to 15.5.2: the adapter applies.
	if !hasDecision(r, NextAdapterDecision) || hasDecision(r, NextDeploymentIDDecision) {
		t.Fatalf("decisions = %v", decisionIDs(r))
	}
	if r.HealthPath != "/docs" || !hasDecision(r, "base-path-health") {
		t.Fatalf("health path %q", r.HealthPath)
	}
	if r.RuntimeEnv["AUTH_TRUST_HOST"] != "true" {
		t.Fatalf("runtime env = %v", r.RuntimeEnv)
	}
	if !slices.Equal(r.EnvAliases, []state.EnvAlias{{Name: "NEXTAUTH_URL", From: state.AliasPublicURL}}) {
		t.Fatalf("aliases = %+v", r.EnvAliases)
	}
	want := []state.GeneratedSecret{
		{Name: "NEXT_SERVER_ACTIONS_ENCRYPTION_KEY", Length: 32, Encoding: state.SecretBase64, Scope: state.SecretBuild, Shared: state.ShareService},
		{Name: "NEXTAUTH_SECRET", Length: 32, Encoding: state.SecretBase64},
	}
	if !slices.Equal(r.GeneratedSecrets, want) {
		t.Fatalf("generated secrets = %+v", r.GeneratedSecrets)
	}
	if hasDecision(r, ForwardedHTTPSDecision) {
		t.Fatal("Next reads X-Forwarded-Proto itself; no preload")
	}
}

func TestNextVersionGates(t *testing.T) {
	cases := []struct {
		next, config string
		adapter, id  bool
		warning      string
	}{
		{next: "15.4.0", adapter: true},
		{next: "^16.0.0", adapter: true},
		{next: "15.3.4", id: true, warning: "standalone-off"},
		{next: "14.2.15", id: true, warning: "standalone-off"},
		{next: "14.2.15", config: `module.exports = { output: 'standalone' }`, id: true, warning: "next-sharp-missing"},
		{next: "13.5.0", warning: "standalone-off"},
		{next: "15.1.0", config: `export default { output: "export" }`},
	}
	for _, c := range cases {
		files := map[string]string{"package.json": `{"dependencies":{"next":"` + c.next + `"}}`}
		if c.config != "" {
			files["next.config.js"] = c.config
		}
		r := resolveJS(t, mapRepo(files), nodeInfo, "nextjs")
		if hasDecision(r, NextAdapterDecision) != c.adapter || hasDecision(r, NextDeploymentIDDecision) != c.id {
			t.Errorf("%s %q: decisions = %v", c.next, c.config, decisionIDs(r))
		}
		if c.warning != "" && !slices.Contains(warningCodes(r), c.warning) {
			t.Errorf("%s: warnings = %v, want %s", c.next, warningCodes(r), c.warning)
		}
		if c.warning == "" && slices.Contains(warningCodes(r), "standalone-off") {
			t.Errorf("%s: unexpected standalone-off", c.next)
		}
	}
}

func TestAuthJSv5(t *testing.T) {
	f := mapRepo(map[string]string{"package.json": `{"dependencies":{"next":"16.2.0","next-auth":"5.0.0-beta.29"}}`})
	r := resolveJS(t, f, nodeInfo, "nextjs")
	// The standalone server sees 0.0.0.0:3000 as the request URL: AUTH_URL follows the domain.
	if r.RuntimeEnv["AUTH_TRUST_HOST"] != "true" || !slices.Equal(r.EnvAliases, []state.EnvAlias{{Name: "AUTH_URL", From: state.AliasPublicURL}}) {
		t.Fatalf("env %v aliases %v", r.RuntimeEnv, r.EnvAliases)
	}
	if !slices.ContainsFunc(r.GeneratedSecrets, func(g state.GeneratedSecret) bool { return g.Name == "AUTH_SECRET" && g.Scope == "" }) {
		t.Fatalf("generated = %+v", r.GeneratedSecrets)
	}
	// Removable like every decision.
	r, _ = Resolve(f, nodeInfo, "", []string{"auth-secret", "auth-trust-host", "server-actions-key", "auth-url"})
	if r.GeneratedSecrets != nil || r.RuntimeEnv["AUTH_TRUST_HOST"] != "" || len(r.EnvAliases) != 0 {
		t.Fatalf("removed decisions still apply: %+v %v", r.GeneratedSecrets, r.RuntimeEnv)
	}
	// next start (Next 15.3 without standalone) sees the Host header: no AUTH_URL.
	r = resolveJS(t, mapRepo(map[string]string{"package.json": `{"dependencies":{"next":"15.3.0","next-auth":"5.0.0-beta.29"}}`}), nodeInfo, "nextjs")
	if hasDecision(r, "auth-url") || !hasDecision(r, "auth-trust-host") {
		t.Fatalf("decisions = %v", decisionIDs(r))
	}
	// No auth library: nothing.
	r = resolveJS(t, mapRepo(map[string]string{"package.json": `{"dependencies":{"next":"16.2.0"}}`}), nodeInfo, "nextjs")
	if hasDecision(r, "auth-trust-host") || hasDecision(r, "auth-secret") {
		t.Fatalf("decisions = %v", decisionIDs(r))
	}
}

func TestNuxt(t *testing.T) {
	r := resolveJS(t, repo(t, "nuxt"), nodeInfo, "nuxt")
	if r.Framework != "nuxt" || r.Plan.StartCommand != "node .output/server/index.mjs" || r.Port != 3000 {
		t.Fatalf("framework %s start %q", r.Framework, r.Plan.StartCommand)
	}
	if len(r.Warnings) != 0 {
		t.Fatalf("stock Nuxt has no warnings: %v", warningCodes(r))
	}
	r = resolveJS(t, mapRepo(map[string]string{
		"package.json":   `{"dependencies":{"nuxt":"^3.17.0"}}`,
		"nuxt.config.ts": "export default defineNuxtConfig({ app: { baseURL: '/blog/' }, nitro: { preset: 'vercel' } })",
	}), nodeInfo, "nuxt")
	if r.HealthPath != "/blog" || !slices.Contains(warningCodes(r), "nitro-preset") {
		t.Fatalf("health %q warnings %v", r.HealthPath, warningCodes(r))
	}
}

func TestSvelteKit(t *testing.T) {
	r := resolveJS(t, repo(t, "sveltekit-node"), nodeInfo, "sveltekit")
	if r.Plan.StartCommand != "node server" || r.HealthPath != "/shop" {
		t.Fatalf("start %q health %q", r.Plan.StartCommand, r.HealthPath)
	}
	if r.RuntimeEnv["ADDRESS_HEADER"] != "X-Forwarded-For" || r.RuntimeEnv["XFF_DEPTH"] != "1" {
		t.Fatalf("runtime env = %v", r.RuntimeEnv)
	}
	if len(r.Warnings) != 0 || hasDecision(r, ForwardedHTTPSDecision) {
		t.Fatalf("warnings %v decisions %v", warningCodes(r), decisionIDs(r))
	}
	cases := map[string]string{
		`import adapter from '@sveltejs/adapter-auto';`:   "",
		`import adapter from '@sveltejs/adapter-node';`:   "",
		`import adapter from '@sveltejs/adapter-static';`: "sveltekit-adapter-static",
		`import adapter from '@sveltejs/adapter-vercel';`: "sveltekit-adapter-unsupported",
	}
	for cfg, warning := range cases {
		r := resolveJS(t, mapRepo(map[string]string{
			"package.json":     `{"devDependencies":{"@sveltejs/kit":"^2.0.0"}}`,
			"svelte.config.js": cfg + "\nexport default { kit: { adapter: adapter() } };",
		}), nodeInfo, "sveltekit")
		codes := warningCodes(r)
		if (warning == "" && len(codes) != 0) || (warning != "" && !slices.Equal(codes, []string{warning})) {
			t.Errorf("%s: warnings = %v", cfg, codes)
		}
		if strings.Contains(cfg, "adapter-node") != (r.Plan.StartCommand == "node build") {
			t.Errorf("%s: start = %q", cfg, r.Plan.StartCommand)
		}
	}
}

func TestAstroNode(t *testing.T) {
	r := resolveJS(t, repo(t, "astro-node"), nodeInfo, "astro")
	if r.Plan.StartCommand != "node ./dist/server/entry.mjs" || r.Framework != "astro" {
		t.Fatalf("start %q", r.Plan.StartCommand)
	}
	if !hasDecision(r, ForwardedHTTPSDecision) || r.Plan.DeployVariables["NODE_OPTIONS"] != "--import="+ForwardedPreloadPath {
		t.Fatalf("preload missing: %v %v", decisionIDs(r), r.Plan.DeployVariables)
	}
	if !strings.Contains(r.Plan.DeployFiles[ForwardedPreloadPath], "x-forwarded-proto") {
		t.Fatalf("deploy files = %v", r.Plan.DeployFiles)
	}
	// A static Astro site is planned as an SPA by Railpack and stays with static-spa.
	resolveJS(t, repo(t, "astro-node"), Info{Provider: "node", Metadata: map[string]string{"nodeIsSPA": "true"}}, "static-spa")
	// Middleware mode has no server to start.
	r = resolveJS(t, mapRepo(map[string]string{
		"package.json":     `{"dependencies":{"astro":"^5.0.0","@astrojs/node":"^9.0.0"}}`,
		"astro.config.mjs": "export default defineConfig({ base: '/docs', adapter: node({ mode: \"middleware\" }) })",
	}), nodeInfo, "astro")
	if r.Plan.StartCommand != "" || !slices.Contains(warningCodes(r), "astro-node-middleware") || r.HealthPath != "/docs" {
		t.Fatalf("start %q warnings %v health %q", r.Plan.StartCommand, warningCodes(r), r.HealthPath)
	}
}

func TestReactRouterRemixTanstack(t *testing.T) {
	r := resolveJS(t, mapRepo(map[string]string{
		"package.json":           `{"scripts":{"build":"react-router build","start":"react-router-serve ./build/server/index.js"},"dependencies":{"@react-router/serve":"^7.8.0","react-router":"^7.8.0"},"devDependencies":{"@react-router/dev":"^7.8.0"}}`,
		"react-router.config.ts": `export default { ssr: true, basename: "/app/" } satisfies Config;`,
	}), nodeInfo, "react-router")
	if r.Plan.StartCommand != "" || !hasDecision(r, ForwardedHTTPSDecision) || r.HealthPath != "/app" {
		t.Fatalf("start %q decisions %v health %q", r.Plan.StartCommand, decisionIDs(r), r.HealthPath)
	}
	r = resolveJS(t, mapRepo(map[string]string{
		"package.json": `{"dependencies":{"@remix-run/serve":"^2.16.0"},"devDependencies":{"@remix-run/dev":"^2.16.0"}}`,
	}), nodeInfo, "react-router")
	if r.Framework != "remix" || r.Plan.StartCommand != "./node_modules/.bin/remix-serve ./build/server/index.js" {
		t.Fatalf("framework %s start %q", r.Framework, r.Plan.StartCommand)
	}
	r = resolveJS(t, mapRepo(map[string]string{
		"package.json": `{"scripts":{"build":"vite build"},"dependencies":{"@tanstack/react-start":"^1.130.0"}}`,
	}), nodeInfo, "tanstack-start")
	if len(r.Warnings) != 0 || r.Plan.StartCommand != "" || !hasDecision(r, ForwardedHTTPSDecision) {
		t.Fatalf("warnings %v start %q", warningCodes(r), r.Plan.StartCommand)
	}
}

func TestAngularSSR(t *testing.T) {
	angular := `{"projects":{"shop":{"architect":{"build":{"options":{"outputPath":"dist/shop","server":"src/main.server.ts","ssr":{"entry":"src/server.ts"},"outputMode":"server"}}}}}}`
	files := map[string]string{
		"package.json": `{"dependencies":{"@angular/core":"^20.0.0","@angular/ssr":"^20.0.0"}}`,
		"angular.json": angular,
	}
	f := mapRepo(files)
	r := resolveJS(t, f, nodeInfo, "angular-ssr")
	if r.Plan.StartCommand != "node dist/shop/server/server.mjs" || r.Port != 3000 {
		t.Fatalf("start %q port %d", r.Plan.StartCommand, r.Port)
	}
	if PlanEnv(f)["RAILPACK_NO_SPA"] != "1" {
		t.Fatal("Angular SSR must not be planned as an SPA")
	}
	r = resolveJS(t, f, Info{Provider: "node", Metadata: map[string]string{"nodeIsSPA": "true"}}, "angular-ssr")
	if r.Port != 8080 || r.Plan.StartCommand != "" || !slices.Contains(warningCodes(r), "angular-ssr-static") {
		t.Fatalf("static plan: port %d start %q warnings %v", r.Port, r.Plan.StartCommand, warningCodes(r))
	}
	// Client-side Angular stays a static site.
	files["angular.json"] = `{"projects":{"shop":{"architect":{"build":{"options":{"outputPath":{"base":"dist/shop"}}}}}}}`
	csr := mapRepo(files)
	resolveJS(t, csr, Info{Provider: "node", Metadata: map[string]string{"nodeIsSPA": "true"}}, "static-spa")
	if PlanEnv(csr) != nil {
		t.Fatal("PlanEnv for a client-side app")
	}
	if got := angularServer(`{"projects":{"x":{"architect":{"build":{"options":{"ssr":true,"outputPath":"../../etc"}}}}}}`); got != "" {
		t.Fatalf("outputPath outside the app accepted: %q", got)
	}
}

func TestGatsbyAndNest(t *testing.T) {
	r := resolveJS(t, mapRepo(map[string]string{
		"package.json": `{"scripts":{"develop":"gatsby develop","start":"gatsby develop","build":"gatsby build"},"dependencies":{"gatsby":"^5.14.0"}}`,
	}), nodeInfo, "gatsby")
	if !strings.Contains(r.Plan.StartCommand, "gatsby serve") {
		t.Fatalf("start %q", r.Plan.StartCommand)
	}
	r = resolveJS(t, mapRepo(map[string]string{
		"package.json": `{"scripts":{"start":"nest start","start:prod":"node dist/main"},"dependencies":{"@nestjs/core":"^11.0.0"}}`,
	}), nodeInfo, "node-server")
	if r.Framework != "nest" || r.Plan.StartCommand != "node dist/main" {
		t.Fatalf("framework %s start %q", r.Framework, r.Plan.StartCommand)
	}
	r = resolveJS(t, mapRepo(map[string]string{
		"package.json": `{"scripts":{"start":"nest start","start:prod":"cross-env NODE_ENV=production node dist/main"},"dependencies":{"@nestjs/core":"^11.0.0"}}`,
	}), nodeInfo, "node-server")
	if r.Plan.StartCommand != "npm run start:prod" {
		t.Fatalf("start %q", r.Plan.StartCommand)
	}
}

func TestForwardedPreloadScope(t *testing.T) {
	express := repo(t, "express")
	if r := resolveJS(t, express, nodeInfo, "node-server"); !hasDecision(r, ForwardedHTTPSDecision) {
		t.Fatalf("decisions = %v", decisionIDs(r))
	}
	for _, info := range []Info{
		{Provider: "node", Packages: map[string]string{"node": "22", "bun": "1.2.0"}},
		{Provider: "node", Packages: map[string]string{"node": "16.20.0"}},
	} {
		if r := resolveJS(t, express, info, "node-server"); hasDecision(r, ForwardedHTTPSDecision) {
			t.Errorf("%v: preload applied", info.Packages)
		}
	}
	r, _ := Resolve(express, nodeInfo, "", []string{ForwardedHTTPSDecision})
	if r.Plan.DeployFiles != nil || r.Plan.DeployVariables["NODE_OPTIONS"] != "" {
		t.Fatalf("removed preload still applied: %+v", r.Plan)
	}
}

func TestLockedVersion(t *testing.T) {
	cases := []struct{ lock, content, want string }{
		{"package-lock.json", `"node_modules/next": {` + "\n" + `      "version": "15.5.2",`, "15.5.2"},
		{"pnpm-lock.yaml", "importers:\n  .:\n    dependencies:\n      next:\n        specifier: ^15.3.0\n        version: 15.5.3(react-dom@19.1.0(react@19.1.0))(react@19.1.0)\n", "15.5.3"},
		{"pnpm-lock.yaml", "dependencies:\n  next: 14.2.3_react@18.2.0\n", "14.2.3"},
		{"yarn.lock", "\"next@^15.3.0\":\n  version \"15.4.1\"\n  resolved \"x\"\n", "15.4.1"},
		{"yarn.lock", "next@npm:^15.3.0:\n  version: 15.4.7\n", "15.4.7"},
		{"bun.lock", `"next": ["next@16.0.1", "", {}, "sha512-x"],`, "16.0.1"},
		{"bun.lock", `"next-auth": ["next-auth@4.24.11", "", {}, "sha512-x"],`, ""},
	}
	for _, c := range cases {
		if got := lockedVersion(c.lock, c.content, "next"); got != c.want {
			t.Errorf("%s %q: got %q, want %q", c.lock, c.content, got, c.want)
		}
	}
	if got := lockedVersion("package-lock.json", `"node_modules/next-auth": {"version": "4.24.11"}`, "next"); got != "" {
		t.Errorf("next matched next-auth: %q", got)
	}
}

func TestBasePath(t *testing.T) {
	for cfg, want := range map[string]string{
		`basePath: '/docs'`:              "/docs",
		"basePath: `/docs/`":             "/docs",
		`basePath: "/"`:                  "",
		`basePath: process.env.BASE`:     "",
		`basePath: "/a b"`:               "",
		`basePath: "/a?x"`:               "",
		`assetPrefix: '/x', basePath:''`: "",
	} {
		if got := basePath(cfg, "basePath"); got != want {
			t.Errorf("%q: got %q, want %q", cfg, got, want)
		}
	}
}
