// Package docker is the daemon's thin layer over the Docker Engine API.
//
// For now it holds the pure conversion from a validated WorkloadSpec to the
// Engine API container-create configuration, with the policy floor applied
// before and after (docs/03 §8). The Engine API client wrapper used by the
// reconciler (docs/10 §4) will live here too; it must only ever touch objects
// labelled launchroom.managed=true.
package docker

import (
	"errors"
	"fmt"
	"net/netip"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/api/types/network"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Labels set on every launchroom-managed object. The reconciler only ever
// lists, changes or removes objects carrying LabelManaged=true.
const (
	LabelManaged     = "launchroom.managed"
	LabelServer      = "launchroom.server"
	LabelWorkload    = "launchroom.workload"
	LabelService     = "launchroom.service"
	LabelEnvironment = "launchroom.environment"
	LabelDeployment  = "launchroom.deployment"
)

var envKeyPattern = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,255}$`)

// WorkloadInput is everything needed to build one container. Env holds the
// decrypted environment; it never leaves memory except via the Engine API.
type WorkloadInput struct {
	ServerID state.ServerID
	Spec     state.WorkloadSpec
	Env      map[string]secret.Secret
	// Networks and Volumes resolve protocol ids to host-side names from the NodeState.
	Networks map[state.NetworkID]state.HostName
	Volumes  map[state.VolumeID]state.HostName
	// MeshIP is this server's mesh address; required if Spec.PublishOnMesh is set.
	MeshIP netip.Addr
}

// ContainerCreate is the input of ContainerCreate in the Engine API.
type ContainerCreate struct {
	Name       string
	Config     *container.Config
	HostConfig *container.HostConfig
	Networking *network.NetworkingConfig
}

// ContainerName is the generated host-side container name for a workload.
func ContainerName(id state.WorkloadID) string { return "lr-" + strings.ToLower(string(id)) }

// BuildContainer converts a validated WorkloadSpec into container-create
// options with the hardened defaults. It enforces policy.Check on the input
// and policy.CheckContainer on the output and fails if either rejects.
func BuildContainer(in WorkloadInput, pc policy.Config) (*ContainerCreate, error) {
	spec := in.Spec
	if err := in.ServerID.Validate(); err != nil {
		return nil, err
	}
	if err := spec.ID.Validate(); err != nil {
		return nil, err
	}
	if err := policy.Check(spec, pc); err != nil {
		return nil, err
	}

	env, err := buildEnv(in.Env)
	if err != nil {
		return nil, err
	}

	cfg := &container.Config{
		Image:      string(spec.Image),
		Entrypoint: slices.Clone(spec.Command),
		Cmd:        slices.Clone(spec.Args),
		Env:        env,
		Labels: map[string]string{
			LabelManaged:     "true",
			LabelServer:      string(in.ServerID),
			LabelWorkload:    string(spec.ID),
			LabelService:     string(spec.Service),
			LabelEnvironment: string(spec.Environment),
			LabelDeployment:  string(spec.Deployment),
		},
	}

	pids := int64(spec.Resources.Pids)
	memory := int64(spec.Resources.MemoryMB) * 1024 * 1024
	hc := &container.HostConfig{
		LogConfig: container.LogConfig{
			Type:   policy.LogDriver,
			Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile},
		},
		RestartPolicy: container.RestartPolicy{Name: container.RestartPolicyMode(spec.Restart)},
		CapDrop:       []string{"ALL"},
		SecurityOpt:   []string{policy.NoNewPrivileges},
		Init:          ptr(true),
		Resources: container.Resources{
			NanoCPUs:   int64(spec.Resources.CPUs * 1e9),
			Memory:     memory,
			MemorySwap: memory, // no swap beyond the memory limit
			PidsLimit:  &pids,
		},
	}
	for _, c := range spec.Capabilities {
		hc.CapAdd = append(hc.CapAdd, string(c))
	}
	if st := spec.Stop; st != nil {
		// Set on the container, so Docker uses them on every stop (also
		// when the host restarts); the reconciler waits at least the
		// timeout (docker.Container.StopTimeout).
		cfg.StopSignal = string(st.Signal)
		cfg.StopTimeout = ptr(int(st.Timeout.Std() / time.Second))
		hc.Init = ptr(!st.NoInit)
	}

	for _, m := range spec.Mounts {
		name, ok := in.Volumes[m.Volume]
		if !ok {
			return nil, fmt.Errorf("docker: workload %s mounts unknown volume %s", spec.ID, m.Volume)
		}
		hc.Mounts = append(hc.Mounts, mount.Mount{
			Type:     mount.TypeVolume,
			Source:   string(name),
			Target:   string(m.Target),
			ReadOnly: m.ReadOnly,
		})
	}

	if spec.Port != nil {
		port, ok := network.PortFrom(uint16(*spec.Port), network.TCP) //nolint:gosec // validated range 1-65535
		if !ok {
			return nil, fmt.Errorf("docker: invalid port %d", *spec.Port)
		}
		cfg.ExposedPorts = network.PortSet{port: {}}
		if spec.PublishOnMesh != nil {
			if !in.MeshIP.IsValid() || in.MeshIP.IsUnspecified() {
				return nil, fmt.Errorf("docker: workload %s publishes on the mesh but this server has no mesh IP", spec.ID)
			}
			hc.PortBindings = network.PortMap{port: {{
				HostIP:   in.MeshIP,
				HostPort: strconv.Itoa(int(spec.PublishOnMesh.Port)),
			}}}
		}
	}

	nc := &network.NetworkingConfig{EndpointsConfig: map[string]*network.EndpointSettings{}}
	for i, att := range spec.Networks {
		name, ok := in.Networks[att.Network]
		if !ok {
			return nil, fmt.Errorf("docker: workload %s attaches unknown network %s", spec.ID, att.Network)
		}
		if i == 0 {
			hc.NetworkMode = container.NetworkMode(name)
		}
		aliases := make([]string, len(att.Aliases))
		for j, a := range att.Aliases {
			aliases[j] = string(a)
		}
		nc.EndpointsConfig[string(name)] = &network.EndpointSettings{Aliases: aliases}
	}
	if hc.NetworkMode == "" {
		return nil, fmt.Errorf("docker: workload %s has no network", spec.ID)
	}

	if err := policy.CheckContainer(cfg, hc, pc); err != nil {
		return nil, fmt.Errorf("docker: generated config violates the policy floor (bug): %w", err)
	}
	return &ContainerCreate{Name: ContainerName(spec.ID), Config: cfg, HostConfig: hc, Networking: nc}, nil
}

// buildEnv renders KEY=value pairs sorted by key, rejecting keys that are not
// portable identifiers and values containing NUL bytes.
func buildEnv(env map[string]secret.Secret) ([]string, error) {
	keys := make([]string, 0, len(env))
	for k := range env {
		keys = append(keys, k)
	}
	slices.Sort(keys)
	out := make([]string, 0, len(keys))
	var errs []error
	for _, k := range keys {
		if !envKeyPattern.MatchString(k) {
			errs = append(errs, fmt.Errorf("docker: invalid env key %q", k))
			continue
		}
		v := env[k].Reveal()
		if strings.ContainsRune(v, 0) {
			errs = append(errs, fmt.Errorf("docker: env %s contains a NUL byte", k))
			continue
		}
		out = append(out, k+"="+v)
	}
	if err := errors.Join(errs...); err != nil {
		return nil, err
	}
	return out, nil
}

func ptr[T any](v T) *T { return &v }
