package reconcile

import (
	"net/netip"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	srvID  = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"
	netA   = state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9N0")
	wlA    = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	wlB    = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8")
	wlGone = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N9")
	imgA   = state.ImageRef("127.0.0.1:5000/apps/a@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
)

var t0 = time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)

func netSpec() state.NetworkSpec {
	return state.NetworkSpec{ID: netA, Name: "lr-e_a", Subnet: "10.231.4.0/24"}
}

func wspec(id state.WorkloadID) state.WorkloadSpec {
	return state.WorkloadSpec{ID: id, Image: imgA, Restart: state.RestartUnlessStopped, Role: state.RoleServing,
		Networks: []state.NetworkAttachment{{Network: netA}}}
}

const (
	volA = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9N1")
	volB = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9N2")
	svcA = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9A1")
	svcB = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9B1")
	envA = state.EnvironmentID("env_01J9Z8X7W6V5T4S3R2Q1P0N9E1")
)

func mounting(s state.WorkloadSpec, vols ...state.VolumeID) state.WorkloadSpec {
	if s.Service == "" {
		s.Service, s.Environment = svcA, envA
	}
	for _, v := range vols {
		s.Mounts = append(s.Mounts, state.Mount{Volume: v, Target: "/data"})
	}
	return s
}

func vol(name string, labels map[string]string) docker.Volume {
	l := docker.ManagedLabels(srvID)
	for k, v := range labels {
		l[k] = v
	}
	return docker.Volume{Name: name, Labels: l}
}

func ctr(id state.WorkloadID, hash, st string) docker.Container {
	l := docker.ManagedLabels(srvID)
	l[docker.LabelWorkload] = string(id)
	l[docker.LabelSpecHash] = hash
	return docker.Container{ID: string(id) + "-" + hash + "-" + st, Name: "lr-" + strings.ToLower(string(id)), Labels: l, State: st, Running: st == "running"}
}

func managedNet(name, subnet string) docker.Network {
	return docker.Network{ID: "id-" + name, Name: name, Labels: docker.ManagedLabels(srvID), Subnets: []netip.Prefix{netip.MustParsePrefix(subnet)}}
}

func kinds(p Plan) []string {
	out := make([]string, len(p.Actions))
	for i, a := range p.Actions {
		out[i] = string(a.Kind) + ":" + string(a.Workload) + a.NetworkName + string(a.Network.Name) + string(a.Volume.Name)
	}
	return out
}

func TestDiff(t *testing.T) {
	grace := 10 * time.Second
	drainSoon, drainPast := t0.Add(time.Minute), t0.Add(-time.Second)
	draining := func(id state.WorkloadID, until time.Time) state.WorkloadSpec {
		s := wspec(id)
		s.Role, s.DrainUntil = state.RoleDraining, &until
		return s
	}
	withRestart := func(s state.WorkloadSpec, r state.RestartPolicy) state.WorkloadSpec { s.Restart = r; return s }

	cases := []struct {
		name    string
		desired Desired
		actual  Actual
		want    []string
		blocked []state.WorkloadID
	}{
		{
			name:    "empty",
			desired: Desired{}, actual: Actual{},
			want: []string{},
		},
		{
			name:    "fresh: network, volume, pull, create",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Volumes: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, Workloads: []DesiredWorkload{{Spec: mounting(wspec(wlA), volA), Hash: "h1"}}},
			actual:  Actual{Images: map[state.ImageRef]bool{}},
			want:    []string{"create-network:lr-e_a", "create-volume:lr-v_a", "pull:" + string(wlA), "create:" + string(wlA)},
		},
		{
			name:    "image present: no pull; one pull per image",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: wspec(wlA), Hash: "h1"}, {Spec: wspec(wlB), Hash: "h2"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Images: map[state.ImageRef]bool{}},
			want:    []string{"pull:" + string(wlA), "create:" + string(wlA), "create:" + string(wlB)},
		},
		{
			name:    "converged: nothing to do",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: wspec(wlA), Hash: "h1"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Containers: []docker.Container{ctr(wlA, "h1", "running")}, Images: map[state.ImageRef]bool{imgA: true}},
			want:    []string{},
		},
		{
			name:    "spec drift: pull before removing, then recreate",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: wspec(wlA), Hash: "h2"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Containers: []docker.Container{ctr(wlA, "h1", "running")}, Images: map[state.ImageRef]bool{}},
			want:    []string{"pull:" + string(wlA), "remove:" + string(wlA), "create:" + string(wlA)},
		},
		{
			name:    "created container is started; exited unless-stopped restarted; exited restart=no left alone",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: wspec(wlA), Hash: "h1"}, {Spec: withRestart(wspec(wlB), state.RestartNo), Hash: "h2"}, {Spec: withRestart(wspec(wlGone), state.RestartAlways), Hash: "h3"}}},
			actual: Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Images: map[state.ImageRef]bool{imgA: true},
				Containers: []docker.Container{ctr(wlA, "h1", "created"), ctr(wlB, "h2", "exited"), ctr(wlGone, "h3", "exited")}},
			want: []string{"start:" + string(wlA), "start:" + string(wlGone)},
		},
		{
			name:    "duplicates: keep the matching running one",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: wspec(wlA), Hash: "h1"}}},
			actual: Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Images: map[state.ImageRef]bool{imgA: true},
				Containers: []docker.Container{ctr(wlA, "h0", "exited"), ctr(wlA, "h1", "running")}},
			want: []string{"remove:" + string(wlA)},
		},
		{
			name:    "undesired containers and networks are removed, containers first",
			desired: Desired{},
			actual: Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Volumes: []docker.Volume{{Name: "lr-v_old"}},
				Containers: []docker.Container{ctr(wlGone, "h", "running")}},
			want: []string{"remove:" + string(wlGone), "remove-network:lr-e_a"},
		},
		{
			name:    "skipped workloads (env/policy failure) keep their container",
			desired: Desired{Skipped: map[state.WorkloadID]string{wlA: "cannot open env"}},
			actual:  Actual{Containers: []docker.Container{ctr(wlA, "h1", "running")}},
			want:    []string{},
		},
		{
			name:    "draining before drainUntil: keep running, never create",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: draining(wlA, drainSoon), Hash: "h9"}, {Spec: draining(wlB, drainSoon), Hash: "h9"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Containers: []docker.Container{ctr(wlA, "h1", "running")}},
			want:    []string{},
		},
		{
			name:    "draining past drainUntil: stop with grace, keep the container",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: draining(wlA, drainPast), Hash: "h1"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Containers: []docker.Container{ctr(wlA, "h1", "running")}},
			want:    []string{"stop:" + string(wlA)},
		},
		{
			name:    "drained and stopped: nothing (not restarted)",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: draining(wlA, drainPast), Hash: "h1"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Containers: []docker.Container{ctr(wlA, "h1", "exited")}},
			want:    []string{},
		},
		{
			name:    "rolling: new serving created while old draining keeps running",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: draining(wlA, drainSoon), Hash: "h1"}, {Spec: wspec(wlB), Hash: "h2"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Containers: []docker.Container{ctr(wlA, "h1", "running")}, Images: map[state.ImageRef]bool{imgA: true}},
			want:    []string{"create:" + string(wlB)},
		},
		{
			name:    "subnet mismatch blocks workloads on that network",
			desired: Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: wspec(wlA), Hash: "h1"}}},
			actual:  Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.9.0/24")}, Images: map[state.ImageRef]bool{imgA: true}},
			want:    []string{},
			blocked: []state.WorkloadID{wlA},
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			p := Diff(tc.desired, tc.actual, t0, grace)
			got := kinds(p)
			if !slices.Equal(got, tc.want) {
				t.Fatalf("got  %q\nwant %q", got, tc.want)
			}
			for _, id := range tc.blocked {
				if _, ok := p.Blocked[id]; !ok {
					t.Errorf("%s not blocked", id)
				}
			}
			if len(p.Blocked) != len(tc.blocked) {
				t.Errorf("blocked %v", p.Blocked)
			}
			// Deterministic.
			if again := kinds(Diff(tc.desired, tc.actual, t0, grace)); !slices.Equal(again, got) {
				t.Fatal("nondeterministic")
			}
		})
	}
}

func TestDiffGrace(t *testing.T) {
	p := Diff(Desired{}, Actual{Containers: []docker.Container{ctr(wlA, "h", "running"), ctr(wlB, "h", "exited")}}, t0, 7*time.Second)
	if p.Actions[0].Grace != 7*time.Second || p.Actions[1].Grace != 0 || !p.Actions[0].Undesired {
		t.Fatalf("%+v", p.Actions)
	}
}

// A container's own stop timeout (WorkloadSpec.stop) extends the grace.
func TestDiffGraceHonoursContainerStopTimeout(t *testing.T) {
	db := ctr(wlA, "h", "running")
	db.StopTimeout = time.Minute
	p := Diff(Desired{}, Actual{Containers: []docker.Container{db, ctr(wlB, "h", "running")}}, t0, 7*time.Second)
	if p.Actions[0].Grace != time.Minute || p.Actions[1].Grace != 7*time.Second {
		t.Fatalf("%+v", p.Actions)
	}
}

func TestDiffNeverTouchesVolumes(t *testing.T) {
	p := Diff(Desired{}, Actual{Volumes: []docker.Volume{{Name: "lr-v_a"}, {Name: "lr-v_b"}}}, t0, time.Second)
	if len(p.Actions) != 0 {
		t.Fatalf("%v", kinds(p))
	}
}

// TestDiffVolumeOwnership covers M5 of the 2026-10 security review: a
// NodeState must not get a workload to mount launchroom's system volumes or
// another service's volume.
func TestDiffVolumeOwnership(t *testing.T) {
	net := managedNet("lr-e_a", "10.231.4.0/24")
	imgs := map[state.ImageRef]bool{imgA: true}
	owned := map[string]string{docker.LabelVolume: string(volA), docker.LabelService: string(svcA), docker.LabelEnvironment: string(envA)}
	cases := []struct {
		name    string
		vols    []state.VolumeSpec
		spec    []state.WorkloadSpec
		actual  []docker.Volume
		want    []string
		blocked bool
	}{
		{name: "own volume", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volA)},
			actual: []docker.Volume{vol("lr-v_a", owned)}, want: []string{"create:" + string(wlA)}},
		{name: "legacy volume without owner labels, matching id", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volA)},
			actual: []docker.Volume{vol("lr-v_a", map[string]string{docker.LabelVolume: string(volA)})}, want: []string{"create:" + string(wlA)}},
		{name: "caddy data volume", vols: []state.VolumeSpec{{ID: volA, Name: "lr-system-caddy-data"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volA)},
			actual: []docker.Volume{vol("lr-system-caddy-data", map[string]string{docker.LabelVolume: "system-caddy-data"})}, want: []string{}, blocked: true},
		{name: "buildkit state volume not yet existing", vols: []state.VolumeSpec{{ID: volA, Name: "lr-system-buildkit-state"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volA)},
			want: []string{}, blocked: true},
		{name: "non workload volume name", vols: []state.VolumeSpec{{ID: volA, Name: "lr-daemon-data"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volA)},
			want: []string{}, blocked: true},
		{name: "system labelled volume with a workload name", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volA)},
			actual: []docker.Volume{vol("lr-v_a", map[string]string{docker.LabelVolume: string(volA), docker.LabelSystem: "caddy"})}, want: []string{}, blocked: true},
		{name: "existing volume of another id", vols: []state.VolumeSpec{{ID: volB, Name: "lr-v_a"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volB)},
			actual: []docker.Volume{vol("lr-v_a", owned)}, want: []string{}, blocked: true},
		{name: "existing volume of another service", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}},
			spec: []state.WorkloadSpec{func() state.WorkloadSpec {
				s := wspec(wlA)
				s.Service, s.Environment = svcB, envA
				return mounting(s, volA)
			}()},
			actual: []docker.Volume{vol("lr-v_a", owned)}, want: []string{}, blocked: true},
		{name: "two services claim one new volume", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}},
			spec: []state.WorkloadSpec{mounting(wspec(wlA), volA), func() state.WorkloadSpec {
				s := wspec(wlB)
				s.Service, s.Environment = svcB, envA
				return mounting(s, volA)
			}()},
			want: []string{}, blocked: true},
		// A prepared volume (docs/14 §13.2): created without an owner before
		// the service that moves here runs.
		{name: "unmounted volume is created without an owner", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, spec: []state.WorkloadSpec{wspec(wlA)},
			want: []string{"create-volume:lr-v_a", "create:" + string(wlA)}},
		{name: "unmounted volume that exists is left alone", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, spec: []state.WorkloadSpec{wspec(wlA)},
			actual: []docker.Volume{vol("lr-v_a", map[string]string{docker.LabelVolume: string(volA)})}, want: []string{"create:" + string(wlA)}},
		{name: "unmounted system volume name is never created", vols: []state.VolumeSpec{{ID: volA, Name: "lr-system-caddy-data"}}, spec: []state.WorkloadSpec{wspec(wlA)},
			want: []string{"create:" + string(wlA)}},
		// The workload that later mounts a prepared volume (no service label) may.
		{name: "prepared volume mounted by its service", vols: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, spec: []state.WorkloadSpec{mounting(wspec(wlA), volA)},
			actual: []docker.Volume{vol("lr-v_a", map[string]string{docker.LabelVolume: string(volA)})}, want: []string{"create:" + string(wlA)}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			d := Desired{Networks: []state.NetworkSpec{netSpec()}, Volumes: tc.vols}
			for _, s := range tc.spec {
				d.Workloads = append(d.Workloads, DesiredWorkload{Spec: s, Hash: "h1"})
			}
			p := Diff(d, Actual{Networks: []docker.Network{net}, Volumes: tc.actual, Images: imgs}, t0, time.Second)
			if got := kinds(p); !slices.Equal(got, tc.want) {
				t.Fatalf("got %q want %q (blocked %v)", got, tc.want, p.Blocked)
			}
			if tc.blocked != (len(p.Blocked) > 0) {
				t.Fatalf("blocked %v", p.Blocked)
			}
		})
	}
	// A created volume carries its owner.
	d := Desired{Networks: []state.NetworkSpec{netSpec()}, Volumes: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}, Workloads: []DesiredWorkload{{Spec: mounting(wspec(wlA), volA), Hash: "h1"}}}
	p := Diff(d, Actual{Networks: []docker.Network{net}, Images: imgs}, t0, time.Second)
	if p.Actions[0].Kind != CreateVolume || p.Actions[0].VolumeOwner != (VolumeOwner{Service: svcA, Environment: envA}) {
		t.Fatalf("%+v", p.Actions)
	}
	// A prepared volume has none.
	d = Desired{Networks: []state.NetworkSpec{netSpec()}, Volumes: []state.VolumeSpec{{ID: volA, Name: "lr-v_a"}}}
	p = Diff(d, Actual{Networks: []docker.Network{net}, Images: imgs}, t0, time.Second)
	if len(p.Actions) != 1 || p.Actions[0].Kind != CreateVolume || p.Actions[0].VolumeOwner != (VolumeOwner{}) {
		t.Fatalf("%+v", p.Actions)
	}
}

func TestDiffRefusesSystemNetworks(t *testing.T) {
	sys := state.NetworkSpec{ID: netA, Name: "lr-system-build", Subnet: "10.232.0.0/24"}
	p := Diff(Desired{Networks: []state.NetworkSpec{sys}, Workloads: []DesiredWorkload{{Spec: wspec(wlA), Hash: "h1"}}}, Actual{Images: map[state.ImageRef]bool{imgA: true}}, t0, time.Second)
	if len(p.Actions) != 0 || p.Blocked[wlA] == "" {
		t.Fatalf("actions %v blocked %v", kinds(p), p.Blocked)
	}
}
