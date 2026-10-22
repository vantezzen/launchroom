package guard

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"path"
	"reflect"
	"regexp"
	"slices"
	"strings"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// class is the kind of container a create request asks for. Each class has
// its own field allow-list and checks; anything that is not one of
// launchroom's system containers is a workload and gets the full policy
// floor (policy.CheckContainer).
type class int

const (
	classWorkload class = iota + 1
	classCaddy
	classTailscale
	classBuildkit
	classAmbassador
	classVolumeHelper
	classUpdater
)

// Names of launchroom's system objects (kept in sync with their packages by
// guard_test.go; the guard does not import them).
const (
	caddyName            = "lr-system-caddy"
	caddyDataVolume      = "lr-system-caddy-data"
	caddyConfigVolume    = "lr-system-caddy-config"
	tailscaleName        = "lr-system-tailscale"
	tailscaleStateVolume = "lr-system-tailscale-state"
	buildkitName         = "lr-system-buildkit"
	buildkitNetwork      = "lr-system-build"
	buildkitStateVolume  = "lr-system-buildkit-state"
	buildkitAppArmor     = "launchroom-buildkit"
	ambassadorName       = "lr-system-ambassador"
	ambassadorNetwork    = "lr-system-ambassador"
	ambassadorVolume     = "lr-system-ambassador-config"
	volumeHelperImage    = "launchroom-local/volume-helper:1"
	volumeHelperMount    = "/data"
	systemAmbassador     = "ambassador"
	roleUpdater          = "updater"
	supervisorBinary     = "/usr/local/bin/launchroomd"
	supervisorCommand    = "update-supervisor"
	dockerSocket         = "/var/run/docker.sock"
	tunDevice            = "/dev/net/tun"
	unprivilegedPortsCtl = "net.ipv4.ip_unprivileged_port_start"
)

var (
	reContainerName = regexp.MustCompile(`^lr-[a-z0-9][a-z0-9_.-]{0,120}$`)
	reHelperName    = regexp.MustCompile(`^lr-vol-[a-f0-9]{12}$`)
	reUpdaterName   = regexp.MustCompile(`^lr-system-update-[a-z0-9]{26}$`)
	reLocalImage    = regexp.MustCompile(`^lr-[a-z0-9][a-z0-9-]{0,62}:[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$`)
	reImageID       = regexp.MustCompile(`^sha256:[a-f0-9]{64}$`)
	reUser          = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$|^[0-9]{1,10}(:[0-9]{1,10})?$`)
	reGroup         = regexp.MustCompile(`^[0-9]{1,10}$`)
	reExtraHost     = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{0,252}:[0-9a-f.:]{2,45}$`)
	reTaskLabel     = regexp.MustCompile(`^tsk_[0-9A-HJKMNP-TV-Z]{26}$`)
	reEnvKey        = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,255}$`)
)

func reflect0(v any) reflect.Value { return reflect.Indirect(reflect.ValueOf(v)) }

// classify decides the class of a container from its name and labels.
func classify(name string, labels map[string]string) class {
	switch {
	case labels[labelRole] == roleUpdater:
		return classUpdater
	case labels[labelHelper] == "volume":
		return classVolumeHelper
	}
	switch name {
	case caddyName:
		return classCaddy
	case tailscaleName:
		return classTailscale
	case buildkitName:
		return classBuildkit
	case ambassadorName:
		return classAmbassador
	}
	return classWorkload
}

// Fields every class may set (the moby structs' Go field names; embedded
// structs such as Resources are flattened). Everything else must be zero.
var (
	baseConfigFields = []string{"Image", "Entrypoint", "Cmd", "Env", "Labels", "User", "ExposedPorts", "StopSignal", "StopTimeout", "NetworkDisabled"}
	baseHostFields   = []string{"NetworkMode", "RestartPolicy", "CapDrop", "CapAdd", "SecurityOpt", "Init", "NanoCPUs", "Memory", "MemorySwap", "PidsLimit", "CPUShares", "LogConfig", "Mounts", "PortBindings"}
	endpointFields   = []string{"Aliases", "GwPriority"}
)

// systemShape describes one of launchroom's system containers.
type systemShape struct {
	// component is the policy.SystemImageDigests key ("" = a local lr- image).
	component string
	name      string
	labelKind string // launchroom.system value
	// network is "host" or the managed network the container runs on.
	network     string
	capAdd      []string
	capDropAll  bool
	securityOpt []string
	// securityOptAlt is the one other exact set allowed (nil = none).
	securityOptAlt []string
	volumes        []string
	extraHost      []string // extra HostConfig fields
	// putRoots are where archive uploads may write ("" = none).
	putRoots []string
	// exec is the allowed argv prefix for execs (nil = no exec at all).
	exec []string
	// execExact requires the argv to equal exec.
	execExact bool
}

var shapes = map[class]systemShape{
	classCaddy: {
		component: policy.SystemCaddy, name: caddyName, labelKind: policy.SystemCaddy, network: "host",
		capAdd: []string{"NET_BIND_SERVICE"}, capDropAll: true, securityOpt: []string{policy.NoNewPrivileges},
		volumes: []string{caddyDataVolume, caddyConfigVolume}, putRoots: []string{"/data"},
	},
	classTailscale: {
		component: policy.SystemTailscale, name: tailscaleName, labelKind: policy.SystemTailscale, network: "host",
		capAdd: []string{"NET_ADMIN", "NET_RAW"}, capDropAll: true, securityOpt: []string{policy.NoNewPrivileges},
		volumes: []string{tailscaleStateVolume}, extraHost: []string{"Devices"}, exec: []string{"tailscale"},
	},
	classBuildkit: {
		component: policy.SystemBuildkit, name: buildkitName, labelKind: policy.SystemBuildkit, network: buildkitNetwork,
		securityOpt: []string{"seccomp=unconfined", "apparmor=unconfined"},
		// Hosts that restrict user namespaces: the installers' AppArmor
		// profile (unconfined plus userns) instead of unconfined (D58).
		securityOptAlt: []string{"seccomp=unconfined", "apparmor=" + buildkitAppArmor},
		volumes:        []string{buildkitStateVolume}, extraHost: []string{"MaskedPaths", "ReadonlyPaths", "ExtraHosts"},
		putRoots: []string{"/home/user/.config"}, exec: []string{"buildctl", "dial-stdio"}, execExact: true,
	},
	classAmbassador: {
		name: ambassadorName, labelKind: systemAmbassador, network: ambassadorNetwork,
		capDropAll: true, securityOpt: []string{policy.NoNewPrivileges},
		volumes: []string{ambassadorVolume}, extraHost: []string{"ReadonlyRootfs", "Sysctls"}, putRoots: []string{"/config"},
	},
}

// createContainer checks POST /containers/create and forwards the
// re-encoded body.
func (s *Server) createContainer(w http.ResponseWriter, r *http.Request, q *request) error {
	var req container.CreateRequest
	if err := decodeStrict(r, &req); err != nil {
		return err
	}
	if req.Config == nil || req.HostConfig == nil {
		return deny("config and host config are required")
	}
	name := q.query.Get("name")
	if name == "" {
		return deny("containers need a generated name (lr-…)")
	}
	if err := s.checkCreate(r.Context(), name, &req); err != nil {
		return err
	}
	body, err := encode(req)
	if err != nil {
		return err
	}
	query := map[string][]string{"name": {name}}
	if p := q.query.Get("platform"); p != "" {
		query["platform"] = []string{p}
	}
	return s.forward(w, r, q, q.apiPath("/containers/create"), query, body, "application/json")
}

// checkCreate is the policy decision for one container (exported for tests
// through CheckCreate).
func (s *Server) checkCreate(ctx context.Context, name string, req *container.CreateRequest) error {
	cfg, hc := req.Config, req.HostConfig
	cls := classify(name, cfg.Labels)
	pol := s.Policy()

	// 1. The field allow-list of the class.
	cfgAllowed, hostAllowed := slices.Clone(baseConfigFields), slices.Clone(baseHostFields)
	switch cls {
	case classUpdater:
		cfgAllowed = append(cfgAllowed, "WorkingDir", "Tty")
		hostAllowed = append(hostAllowed, "GroupAdd")
	default:
		if sh, ok := shapes[cls]; ok {
			hostAllowed = append(hostAllowed, sh.extraHost...)
		}
	}
	if f := nonZeroOutside(reflect.ValueOf(*cfg), cfgAllowed); len(f) > 0 {
		return deny("container %s: config fields %s are not allowed", name, strings.Join(f, ", "))
	}
	if f := nonZeroOutside(reflect.ValueOf(*hc), hostAllowed); len(f) > 0 {
		return deny("container %s: host config fields %s are not allowed", name, strings.Join(f, ", "))
	}
	endpoints := map[string]bool{}
	if nc := req.NetworkingConfig; nc != nil {
		for n, ep := range nc.EndpointsConfig {
			if ep != nil {
				if f := nonZeroOutside(reflect.ValueOf(*ep), endpointFields); len(f) > 0 {
					return deny("container %s: endpoint fields %s are not allowed", name, strings.Join(f, ", "))
				}
			}
			endpoints[n] = true
		}
	}

	// 2. Labels and names.
	if cls != classUpdater {
		if !isManaged(cfg.Labels) {
			return deny("container %s must carry launchroom.managed=true and a valid launchroom.server label", name)
		}
		if !reContainerName.MatchString(name) {
			return deny("container name %q is not a generated name (lr-…)", truncate(name, 64))
		}
	}

	switch cls {
	case classUpdater:
		return s.checkUpdater(ctx, name, cfg, hc, endpoints)
	case classVolumeHelper:
		return s.checkVolumeHelper(ctx, name, cfg, hc, endpoints)
	case classWorkload:
		if strings.HasPrefix(name, policy.SystemPrefix) {
			return deny("%s… names are reserved for launchroom's system containers", policy.SystemPrefix)
		}
		if err := policy.CheckContainer(cfg, hc, pol); err != nil {
			return deny("container %s: %v", name, err)
		}
		if err := s.checkNetworks(ctx, string(hc.NetworkMode), endpoints, false); err != nil {
			return err
		}
		return s.checkMounts(ctx, hc.Mounts, nil)
	}
	return s.checkSystem(ctx, cls, name, cfg, hc, endpoints, pol)
}

// checkSystem checks one of the system shapes.
func (s *Server) checkSystem(ctx context.Context, cls class, name string, cfg *container.Config, hc *container.HostConfig, endpoints map[string]bool, pol policy.Config) error {
	sh := shapes[cls]
	if name != sh.name || cfg.Labels[labelSystem] != sh.labelKind {
		return deny("container %s: system containers need their own name and launchroom.system label", name)
	}
	if sh.component != "" {
		if err := policy.CheckSystemImage(sh.component, state.ImageRef(cfg.Image), pol); err != nil {
			return deny("container %s: %v", name, err)
		}
	} else if !reLocalImage.MatchString(cfg.Image) {
		return deny("container %s: image %q must be launchroomd's locally built lr- image", name, truncate(cfg.Image, 96))
	}
	if string(hc.NetworkMode) != sh.network {
		return deny("container %s: network mode %q is not allowed (want %s)", name, hc.NetworkMode, sh.network)
	}
	if sh.network == "host" {
		if len(endpoints) > 0 || len(hc.PortBindings) > 0 {
			return deny("container %s: host-network system containers attach no networks and publish no ports", name)
		}
	} else if err := s.checkNetworks(ctx, sh.network, endpoints, true); err != nil {
		return err
	}
	if len(hc.PortBindings) > 0 || len(cfg.ExposedPorts) > 0 {
		return deny("container %s: system containers publish no ports", name)
	}
	if sh.capDropAll && !slices.Equal(upper(hc.CapDrop), []string{"ALL"}) {
		return deny("container %s: cap-drop ALL is required", name)
	}
	if !sh.capDropAll && len(hc.CapDrop) > 0 {
		return deny("container %s: unexpected cap-drop", name)
	}
	for _, c := range hc.CapAdd {
		if !slices.Contains(sh.capAdd, strings.TrimPrefix(strings.ToUpper(c), "CAP_")) {
			return deny("container %s: capability %s is not allowed", name, c)
		}
	}
	if !sameSet(hc.SecurityOpt, sh.securityOpt) && (sh.securityOptAlt == nil || !sameSet(hc.SecurityOpt, sh.securityOptAlt)) {
		return deny("container %s: security options %v are not allowed (want %v)", name, hc.SecurityOpt, sh.securityOpt)
	}
	if err := checkLogConfig(hc.LogConfig); err != nil {
		return deny("container %s: %v", name, err)
	}
	if hc.PidsLimit == nil || *hc.PidsLimit <= 0 || (hc.Memory <= 0 && cls != classBuildkit) {
		return deny("container %s: memory and pids limits are required", name)
	}
	if err := s.checkMounts(ctx, hc.Mounts, sh.volumes); err != nil {
		return err
	}
	switch cls {
	case classTailscale:
		if len(hc.Devices) != 1 || hc.Devices[0].PathOnHost != tunDevice || hc.Devices[0].PathInContainer != tunDevice || hc.Devices[0].CgroupPermissions != "rwm" {
			return deny("container %s: only %s may be mapped", name, tunDevice)
		}
	case classBuildkit:
		if len(hc.MaskedPaths) > 0 || len(hc.ReadonlyPaths) > 0 {
			return deny("container %s: masked/read-only paths may only be emptied", name)
		}
		for _, h := range hc.ExtraHosts {
			if !reExtraHost.MatchString(h) {
				return deny("container %s: extra host %q is not allowed", name, truncate(h, 64))
			}
		}
	case classAmbassador:
		if len(hc.Sysctls) != 1 || hc.Sysctls[unprivilegedPortsCtl] != "0" {
			return deny("container %s: only %s=0 is allowed", name, unprivilegedPortsCtl)
		}
		if !hc.ReadonlyRootfs || hc.Init == nil || !*hc.Init || hc.NanoCPUs <= 0 {
			return deny("container %s: read-only root, init and a CPU limit are required", name)
		}
	}
	return nil
}

// checkVolumeHelper: created, never started (the guard refuses the start),
// no network, one named volume at /data.
func (s *Server) checkVolumeHelper(ctx context.Context, name string, cfg *container.Config, hc *container.HostConfig, endpoints map[string]bool) error {
	switch {
	case !reHelperName.MatchString(name):
		return deny("volume helper name %q is not generated", truncate(name, 64))
	case cfg.Image != volumeHelperImage:
		return deny("volume helpers run %s only", volumeHelperImage)
	case string(hc.NetworkMode) != "none" || !cfg.NetworkDisabled || len(endpoints) > 0:
		return deny("volume helpers have no network")
	case !slices.Equal(upper(hc.CapDrop), []string{"ALL"}) || len(hc.CapAdd) > 0:
		return deny("volume helpers drop every capability")
	case len(hc.Mounts) != 1 || hc.Mounts[0].Target != volumeHelperMount:
		return deny("volume helpers mount exactly one volume at %s", volumeHelperMount)
	case len(hc.PortBindings) > 0 || len(cfg.ExposedPorts) > 0 || len(cfg.Env) > 0 || cfg.User != "":
		return deny("volume helpers have no ports, environment or user")
	}
	return s.checkMounts(ctx, hc.Mounts, nil)
}

// checkUpdater: the self-update supervisor (internal/update). It is the
// only container with host access: the Docker socket and the install
// folder, from launchroomd's own (running, known-good) image, with the
// fixed entrypoint and one plan path inside the install folder. The guard
// refuses every exec into it and every archive call on it, so nothing but
// the image's own code ever runs there.
func (s *Server) checkUpdater(ctx context.Context, name string, cfg *container.Config, hc *container.HostConfig, endpoints map[string]bool) error {
	u := s.o.Updater
	if u == nil {
		return deny("self-update supervisors are not allowed on this server (dockerd-guard has no install folder configured)")
	}
	if !reUpdaterName.MatchString(name) {
		return deny("supervisor name %q is not generated", truncate(name, 64))
	}
	l := cfg.Labels
	if l[labelManaged] != "true" || !reTaskLabel.MatchString(l["launchroom.update-task"]) || len(l) != 3 {
		return deny("supervisor labels must be exactly managed, role and update-task")
	}
	if !reImageID.MatchString(cfg.Image) {
		return deny("the supervisor runs from an image id")
	}
	images, err := u.Images(ctx)
	if err != nil {
		return deny("cannot determine launchroomd's image: %v", err)
	}
	if !slices.Contains(images, cfg.Image) {
		return deny("the supervisor must run launchroomd's current image")
	}
	if !slices.Equal(cfg.Entrypoint, []string{supervisorBinary, supervisorCommand}) || len(cfg.Cmd) != 1 {
		return deny("the supervisor entrypoint is fixed (%s %s <plan>)", supervisorBinary, supervisorCommand)
	}
	plan := cfg.Cmd[0]
	if path.Clean(plan) != plan || !strings.HasPrefix(plan, u.ProjectDir+"/") || path.Base(plan) != "plan.json" || !strings.Contains(plan, "/update/tasks/") {
		return deny("supervisor plan path %q is not inside the install folder's update tasks", truncate(plan, 128))
	}
	if len(cfg.Env) > 0 || cfg.User != "" || cfg.WorkingDir != "" || len(cfg.ExposedPorts) > 0 {
		return deny("the supervisor gets no environment, user, working dir or ports")
	}
	if string(hc.NetworkMode) != "none" || len(endpoints) > 0 || len(hc.PortBindings) > 0 {
		return deny("the supervisor has no network")
	}
	if len(hc.CapAdd) > 0 {
		return deny("the supervisor gets no capabilities")
	}
	for _, g := range hc.GroupAdd {
		if !reGroup.MatchString(g) {
			return deny("supervisor group %q must be numeric", truncate(g, 32))
		}
	}
	want := []mount.Mount{
		{Type: mount.TypeBind, Source: u.SocketHostPath, Target: dockerSocket},
		{Type: mount.TypeBind, Source: u.ProjectDir, Target: u.ProjectDir},
	}
	if len(hc.Mounts) != len(want) {
		return deny("the supervisor mounts exactly the Docker socket and the install folder")
	}
	for i, m := range hc.Mounts {
		if m.Type != want[i].Type || m.Source != want[i].Source || m.Target != want[i].Target || m.ReadOnly ||
			m.BindOptions != nil || m.VolumeOptions != nil || m.TmpfsOptions != nil || m.ImageOptions != nil || m.Consistency != "" {
			return deny("the supervisor mounts exactly the Docker socket and the install folder")
		}
	}
	if err := checkLogConfig(hc.LogConfig); err != nil {
		return deny("supervisor: %v", err)
	}
	return nil
}

// checkNetworks requires every attached network to be launchroom's.
func (s *Server) checkNetworks(ctx context.Context, mode string, endpoints map[string]bool, system bool) error {
	names := map[string]bool{}
	for n := range endpoints {
		names[n] = true
	}
	switch mode {
	case "none":
		if len(endpoints) > 0 {
			return deny("a container without network attaches no networks")
		}
		return nil
	case "", "default", "bridge", "host":
		return deny("network mode %q is not allowed: workloads run on launchroom networks", mode)
	}
	if strings.HasPrefix(mode, "container:") || strings.ContainsAny(mode, ":/") {
		return deny("network mode %q is not allowed", truncate(mode, 64))
	}
	names[mode] = true
	for n := range names {
		if !system && strings.HasPrefix(n, policy.SystemPrefix) {
			return deny("workloads cannot join system network %s", n)
		}
		info, _, err := s.inspectNetwork(ctx, nil, n)
		if err != nil {
			return err
		}
		if !isManaged(info.Labels) || info.Name != n {
			return notManaged("network", n)
		}
	}
	return nil
}

// checkMounts requires named volumes that are launchroom's (or do not exist
// yet: Docker creates an empty one). With allowed set, only those names.
func (s *Server) checkMounts(ctx context.Context, ms []mount.Mount, allowed []string) error {
	for _, m := range ms {
		if m.Type != mount.TypeVolume || m.BindOptions != nil || m.TmpfsOptions != nil || m.ImageOptions != nil {
			return deny("only named volume mounts are allowed")
		}
		if m.VolumeOptions != nil && (m.VolumeOptions.DriverConfig != nil || m.VolumeOptions.Subpath != "" || len(m.VolumeOptions.Labels) > 0 || m.VolumeOptions.NoCopy) {
			return deny("volume options are not allowed")
		}
		if allowed != nil {
			if !slices.Contains(allowed, m.Source) {
				return deny("volume %s is not allowed here", truncate(m.Source, 64))
			}
		} else if !strings.HasPrefix(m.Source, "lr-") || strings.HasPrefix(m.Source, policy.SystemPrefix) {
			return deny("volume %s is not a launchroom workload volume", truncate(m.Source, 64))
		}
		if !reObject.MatchString(m.Source) {
			return deny("volume name %q is not allowed", truncate(m.Source, 64))
		}
		if err := state.ContainerPath(m.Target).Validate(); err != nil {
			return deny("mount target: %v", err)
		}
		v, _, err := s.inspectVolume(ctx, nil, m.Source)
		var re *refusal
		switch {
		case err == nil:
			if !isManaged(v.Labels) || v.Driver != "local" {
				return notManaged("volume", m.Source)
			}
		case asRefusal(err, &re) && re.status == http.StatusNotFound:
		default:
			return err
		}
	}
	return nil
}

func checkLogConfig(lc container.LogConfig) error {
	if lc.Type != policy.LogDriver || lc.Config["max-size"] != policy.LogMaxSize || lc.Config["max-file"] != policy.LogMaxFile || len(lc.Config) != 2 {
		return fmt.Errorf("log driver must be %s with max-size=%s,max-file=%s", policy.LogDriver, policy.LogMaxSize, policy.LogMaxFile)
	}
	return nil
}

// nonZeroOutside lists the non-zero fields of struct v that are not in
// allowed. Embedded structs are flattened (HostConfig.Resources).
func nonZeroOutside(v reflect.Value, allowed []string) []string {
	var out []string
	t := v.Type()
	for i := range t.NumField() {
		f := t.Field(i)
		if !f.IsExported() {
			continue
		}
		fv := v.Field(i)
		if f.Anonymous {
			ft := f.Type
			if ft.Kind() == reflect.Pointer {
				if fv.IsNil() {
					continue
				}
				fv, ft = fv.Elem(), ft.Elem()
			}
			if ft.Kind() == reflect.Struct {
				out = append(out, nonZeroOutside(fv, allowed)...)
				continue
			}
		}
		if !fv.IsZero() && !slices.Contains(allowed, f.Name) {
			out = append(out, f.Name)
		}
	}
	return out
}

func upper(xs []string) []string {
	out := make([]string, len(xs))
	for i, x := range xs {
		out[i] = strings.TrimPrefix(strings.ToUpper(x), "CAP_")
	}
	return out
}

func sameSet(a, b []string) bool {
	a, b = slices.Clone(a), slices.Clone(b)
	slices.Sort(a)
	slices.Sort(b)
	return slices.Equal(slices.Compact(a), slices.Compact(b)) && len(a) == len(b)
}

func asRefusal(err error, re **refusal) bool {
	r, ok := err.(*refusal) //nolint:errorlint // refusals are never wrapped
	if ok {
		*re = r
	}
	return ok
}

// CheckCreate runs the create decision without forwarding (tests, fuzzing).
func (s *Server) CheckCreate(ctx context.Context, name string, body []byte) error {
	var req container.CreateRequest
	if err := json.Unmarshal(body, &req); err != nil {
		return err
	}
	if req.Config == nil || req.HostConfig == nil {
		return deny("config and host config are required")
	}
	return s.checkCreate(ctx, name, &req)
}
