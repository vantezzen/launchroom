// Package doctor is `launchroomd doctor`: typed checks of a launchroom
// install that work while the dashboard is down, safe fixes after a typed
// "yes", and a redacted support bundle (docs/11 §2.3, docs/15).
//
// It runs in a one-off container the host helper starts:
//
//   - on the control-plane server (`./launchroom doctor`) with the host's
//     Docker socket, the install folder at its own path and the host's root
//     read-only at /host (like the update supervisor, docs/09 §6), so it can
//     see and fix the control plane's containers;
//   - on any other server (`docker compose run --rm launchroomd doctor` in
//     launchroomd's folder) as launchroomd itself, through dockerd-guard's
//     socket, which shows launchroom's own containers only.
//
// Every check has a short timeout and never changes anything. A check that
// can't run because an earlier one failed is skipped with the reason.
// Fixes never delete user data and are idempotent.
package doctor

import (
	"context"
	"net"
	"net/http"
	"slices"
	"time"
)

// Status of one check.
type Status string

// Statuses.
const (
	StatusOK   Status = "ok"
	StatusWarn Status = "warn"
	StatusFail Status = "fail"
	StatusSkip Status = "skip"
)

// Mode says where the doctor runs.
type Mode string

// Modes.
const (
	ModeControlPlane Mode = "control-plane"
	ModeServer       Mode = "server"
)

// Result is one check's outcome.
type Result struct {
	ID      string   `json:"id"`
	Group   string   `json:"group"`
	Title   string   `json:"title"`
	Status  Status   `json:"status"`
	Summary string   `json:"summary"`
	Detail  []string `json:"detail,omitempty"`
	Fix     *FixRef  `json:"fix,omitempty"`
}

// FixRef names the fix for a failed or warning check.
type FixRef struct {
	ID FixID `json:"id"`
	// Target is the service or task the fix applies to.
	Target string `json:"target,omitempty"`
	// Does says in one sentence what the fix changes.
	Does string `json:"does"`
}

// Report is a whole run.
type Report struct {
	Mode      Mode      `json:"mode"`
	Version   string    `json:"version"`
	Dir       string    `json:"dir"`
	CheckedAt time.Time `json:"checkedAt"`
	Results   []Result  `json:"results"`
}

// Failed reports whether any check failed.
func (r Report) Failed() bool {
	return slices.ContainsFunc(r.Results, func(x Result) bool { return x.Status == StatusFail })
}

// Fixes lists the fixes the report offers, in order, without duplicates.
func (r Report) Fixes() []FixRef {
	var out []FixRef
	for _, x := range r.Results {
		if x.Fix == nil || (x.Status != StatusFail && x.Status != StatusWarn) {
			continue
		}
		if !slices.ContainsFunc(out, func(f FixRef) bool { return f.ID == x.Fix.ID && f.Target == x.Fix.Target }) {
			out = append(out, *x.Fix)
		}
	}
	return out
}

// Options configure a run.
type Options struct {
	Mode   Mode
	Engine Engine
	// Dir is the install folder (control plane: mounted at its own path).
	Dir string
	// DataDir is launchroomd's data directory (control plane:
	// Dir/data/daemon; server mode: /data).
	DataDir string
	// HostRoot is where the host's root is mounted read-only ("/host"), or
	// "" (server mode).
	HostRoot string
	// DockerFS is a path on Docker's filesystem (server mode: the volume
	// mounted into launchroomd for that purpose), "" to derive it from
	// HostRoot and the engine's root directory.
	DockerFS string
	// ProcDir is /proc (tests: a fake one).
	ProcDir string
	// Version is this launchroomd's version.
	Version string
	// Command repeats the doctor in fix hints ("./launchroom doctor").
	Command string
	// ControlPlaneURL is launchroomd's dashboard address (server mode).
	ControlPlaneURL string
	// TLSAddr is where the edge Caddy answers HTTPS (127.0.0.1:443).
	TLSAddr string
	// DashboardAddr is the dashboard's loopback HTTP address (127.0.0.1:8000).
	DashboardAddr string
	// RegistryAddr is Zot's loopback address (127.0.0.1:5000).
	RegistryAddr string
	Resolver     Resolver
	HTTP         *http.Client
	Now          func() time.Time
	// CheckTimeout bounds each check (default 5 s; artisan 20 s); the whole
	// run is bounded by RunTimeout (default 60 s).
	CheckTimeout time.Duration
	RunTimeout   time.Duration
}

// Resolver looks up host names.
type Resolver interface {
	LookupHost(ctx context.Context, host string) ([]string, error)
}

func (o *Options) defaults() {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.ProcDir == "" {
		o.ProcDir = "/proc"
	}
	if o.Command == "" {
		o.Command = "./launchroom doctor"
		if o.Mode == ModeServer {
			o.Command = "docker compose run --rm launchroomd doctor"
		}
	}
	if o.TLSAddr == "" {
		o.TLSAddr = "127.0.0.1:443"
	}
	if o.DashboardAddr == "" {
		o.DashboardAddr = "127.0.0.1:8000"
	}
	if o.RegistryAddr == "" {
		o.RegistryAddr = "127.0.0.1:5000"
	}
	if o.Resolver == nil {
		o.Resolver = net.DefaultResolver
	}
	if o.HTTP == nil {
		o.HTTP = &http.Client{Timeout: 5 * time.Second}
	}
	if o.CheckTimeout <= 0 {
		o.CheckTimeout = 5 * time.Second
	}
	if o.RunTimeout <= 0 {
		o.RunTimeout = 60 * time.Second
	}
}

// Run runs every check of the mode and returns the report.
func Run(ctx context.Context, o Options) Report {
	o.defaults()
	ctx, cancel := context.WithTimeout(ctx, o.RunTimeout)
	defer cancel()
	r := &run{o: o, env: map[string]string{}}
	r.load(ctx)
	if o.Mode == ModeServer {
		r.serverChecks(ctx)
	} else {
		r.controlPlaneChecks(ctx)
	}
	return Report{Mode: o.Mode, Version: o.Version, Dir: o.Dir, CheckedAt: o.Now().UTC(), Results: r.results}
}

// run is the state of one doctor run.
type run struct {
	o       Options
	env     map[string]string
	results []Result
	// containers of the install (nil when Docker didn't answer).
	containers []Container
	dockerErr  error
	info       EngineInfo
}

func (r *run) add(res Result) { r.results = append(r.results, res) }

// within runs f with the per-check timeout.
func (r *run) within(ctx context.Context, d time.Duration, f func(context.Context)) {
	if d <= 0 {
		d = r.o.CheckTimeout
	}
	cctx, cancel := context.WithTimeout(ctx, d)
	defer cancel()
	f(cctx)
}

func (r *run) service(name string) (Container, bool) {
	for _, c := range r.containers {
		if c.Service == name {
			return c, true
		}
	}
	return Container{}, false
}
