//go:build integration

package integration

import (
	"context"
	"errors"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/client"

	"github.com/moby/moby/api/types/network"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func networkingFor(name string) *network.NetworkingConfig {
	return &network.NetworkingConfig{EndpointsConfig: map[string]*network.EndpointSettings{name: {}}}
}

// vanishingEngine removes the workload's network right before the first
// container create, like a concurrent removal (a reconcile pass racing a
// run_once task) or a network Docker does not see yet.
type vanishingEngine struct {
	*docker.Engine
	api  *client.Client
	done bool
}

func (v *vanishingEngine) CreateContainer(ctx context.Context, cc *docker.ContainerCreate) (string, error) {
	if !v.done {
		v.done = true
		if _, err := v.api.NetworkRemove(ctx, "lr-e_itest", client.NetworkRemoveOptions{}); err != nil {
			return "", err
		}
	}
	return v.Engine.CreateContainer(ctx, cc)
}

// Real Docker answers "network … not found" on create; the reconciler
// re-creates the network and retries instead of failing the workload.
func TestNetworkNotFoundIsRetried(t *testing.T) {
	e := setup(t)
	// Rebuild the reconciler on top of the vanishing engine.
	v := &vanishingEngine{Engine: e.engine, api: e.api}
	ctx, cancel := context.WithTimeout(t.Context(), 4*time.Minute)
	defer cancel()

	ns, wl := e.nodeState(t, 1, true, false)
	// Pull first so the create happens in the same pass as the network.
	if err := e.engine.PullImage(ctx, string(whoamiImage)); err != nil {
		t.Fatal(err)
	}
	// Docker (29) accepts a container for a missing network and fails on
	// start: "network … not found". That is the error the previews saw; it
	// must be recognised so the reconciler re-creates the network and retries.
	probe, err := fixtureEngine(t, e.serverID).CreateContainer(ctx, &docker.ContainerCreate{Name: "lr-probe", Config: &container.Config{Image: string(whoamiImage), Labels: docker.ManagedLabels(string(e.serverID))},
		HostConfig: &container.HostConfig{}, Networking: networkingFor("lr-e_doesnotexist")})
	if err == nil {
		err = e.engine.StartContainer(ctx, probe)
		_ = e.engine.RemoveContainer(ctx, probe)
	}
	if !docker.IsNetworkNotFound(err) {
		t.Fatalf("Docker's missing-network error is not recognised: %v", err)
	}

	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/traefik/"}
	rec := reconcile.New(reconcile.Options{
		ServerID: e.serverID, Docker: v, Policy: pol, EnvHashKey: reconcile.EnvHashKey(e.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, e.key, e.serverID, s.ID)
		},
		StopGrace: 2 * time.Second,
	})
	if err := rec.Reconcile(ctx, ns); err != nil {
		t.Fatalf("pass failed although the network was re-created: %v", err)
	}
	if !v.done {
		t.Fatal("the network was never removed (test did not exercise the race)")
	}
	_, ws, _ := rec.Snapshot()
	for _, w := range ws {
		if w.ID == wl && w.Phase == state.PhaseFailed {
			t.Fatalf("workload failed: %+v", w)
		}
	}
	cs, err := e.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if err != nil || len(cs) != 1 {
		t.Fatalf("containers %v %v", cs, err)
	}
}

// Volumes are only deleted on explicit request, with matching labels, when
// unused; Docker's own in-use check backs it up.
func TestVolumeDeletionAgainstDocker(t *testing.T) {
	e := setup(t)
	ctx, cancel := context.WithTimeout(t.Context(), 4*time.Minute)
	defer cancel()
	const (
		volID = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1")
		svcID = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2")
		envID = state.EnvironmentID("env_01J9Z8X7W6V5T4S3R2Q1P0N9N4")
	)
	labels := docker.ManagedLabels(string(e.serverID))
	labels[docker.LabelVolume] = string(volID)
	labels[docker.LabelService] = string(svcID)
	labels[docker.LabelEnvironment] = string(envID)
	if err := e.engine.CreateVolume(ctx, docker.VolumeCreate{Name: "lr-v_itestdel", Labels: labels}); err != nil {
		t.Fatal(err)
	}
	if err := e.engine.PullImage(ctx, string(whoamiImage)); err != nil {
		t.Fatal(err)
	}
	userLabels := docker.ManagedLabels(string(e.serverID))
	userLabels[docker.LabelTask] = "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9V2"
	user, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{Name: "lr-run-itest", Config: &container.Config{Image: string(whoamiImage), Labels: userLabels},
		HostConfig: &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: "lr-v_itestdel", Target: "/data"}}}})
	if err != nil {
		t.Fatal(err)
	}

	ns, _ := e.nodeState(t, 1, false, false)
	ns.DeleteVolumes = []state.VolumeDeletion{{ID: volID, Name: "lr-v_itestdel", Service: svcID, Environment: envID, RequestedAt: time.Now().UTC()}}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	_ = e.rec.Reconcile(ctx, ns)
	res := e.rec.VolumeDeletions()
	if len(res) != 1 || res[0].Outcome != state.VolumeRefused || !strings.Contains(res[0].Message, "lr-run-itest") {
		t.Fatalf("in-use volume: %+v", res)
	}
	// Docker refuses too (never forced).
	if err := e.engine.RemoveVolume(ctx, "lr-v_itestdel"); err == nil {
		t.Fatal("Docker removed a volume in use")
	}

	if _, err := e.api.ContainerRemove(ctx, user.ID, client.ContainerRemoveOptions{Force: true}); err != nil {
		t.Fatal(err)
	}
	ns.Version = 2
	if err := e.rec.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if res := e.rec.VolumeDeletions(); len(res) != 1 || res[0].Outcome != state.VolumeDeleted {
		t.Fatalf("unused volume: %+v", res)
	}
	vols, _ := e.engine.ListVolumes(ctx)
	if slices.ContainsFunc(vols, func(v docker.Volume) bool { return v.Name == "lr-v_itestdel" }) {
		t.Fatal("volume still exists")
	}

	// A foreign volume of a requested name is never touched.
	if _, err := e.api.VolumeCreate(ctx, client.VolumeCreateOptions{Name: "lr-v_itestforeign"}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = e.api.VolumeRemove(context.Background(), "lr-v_itestforeign", client.VolumeRemoveOptions{})
	})
	if err := e.engine.RemoveVolume(ctx, "lr-v_itestforeign"); !errors.Is(err, docker.ErrNotManaged) {
		t.Fatalf("foreign volume: %v", err)
	}
	ns.Version = 3
	ns.DeleteVolumes = []state.VolumeDeletion{{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9V3", Name: "lr-v_itestforeign", Service: svcID, Environment: envID, RequestedAt: time.Now().UTC()}}
	_ = e.rec.Reconcile(ctx, ns)
	if res := e.rec.VolumeDeletions(); len(res) != 1 || res[0].Outcome != state.VolumeAbsent {
		t.Fatalf("foreign volume outcome: %+v", res)
	}
	if _, err := e.api.VolumeInspect(ctx, "lr-v_itestforeign", client.VolumeInspectOptions{}); err != nil {
		t.Fatalf("foreign volume was deleted: %v", err)
	}
}
