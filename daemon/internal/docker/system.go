package docker

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/names"
)

// SystemContainer is one of launchroomd's own containers (Caddy, the mesh
// client, BuildKit, the ambassador). Ensure converges it the same way for
// every component: the spec-hash label is computed from the actual spec, so
// any change to it recreates the container.
type SystemContainer struct {
	// Component is the names.System* value of the LabelSystem label.
	Component string
	// Spec is the container to run; Ensure sets its spec-hash label.
	Spec *ContainerCreate
	// Extra is what else the container depends on (hashed with the spec).
	Extra []string
	// Volumes are created, labelled as the component's, before the container.
	Volumes []string
	// Pull pulls the image when it is absent (false: built locally).
	Pull bool
	// StopGrace is how long a replaced container may take to stop (default 10 s).
	StopGrace time.Duration
	// Install runs after create, before the first start (config files).
	Install func(ctx context.Context, id string) error
}

// Hash is the spec-hash label value Ensure gives the container.
func (s SystemContainer) Hash() string { return SystemSpecHash(s.Spec, s.Extra...) }

// Ensure keeps the component's container whose name and spec hash match and
// replaces every other one, then creates the volumes, pulls the image,
// creates and starts the container as needed. created reports a new container.
func (s SystemContainer) Ensure(ctx context.Context, d Client, log *slog.Logger) (c Container, created bool, err error) {
	hash := s.Hash()
	cs, err := d.ListContainers(ctx, map[string]string{names.LabelSystem: s.Component})
	if err != nil {
		return Container{}, false, err
	}
	grace := s.StopGrace
	if grace == 0 {
		grace = 10 * time.Second
	}
	var current *Container
	for i := range cs {
		if current == nil && cs[i].Name == s.Spec.Name && cs[i].Labels[names.LabelSpecHash] == hash {
			current = &cs[i]
			continue
		}
		log.Info("replacing system container", "component", s.Component, "container", cs[i].Name)
		if cs[i].Running() {
			if err := d.StopContainer(ctx, cs[i].ID, grace); err != nil {
				log.Warn("system container did not stop; removing it anyway", "container", cs[i].Name, "err", err)
			}
		}
		if err := d.RemoveContainer(ctx, cs[i].ID); err != nil {
			return Container{}, false, err
		}
	}
	if current == nil {
		if current, err = s.create(ctx, d, hash, log); err != nil {
			return Container{}, false, err
		}
		created = true
	}
	if !current.Running() {
		if err := d.StartContainer(ctx, current.ID); err != nil {
			return Container{}, created, err
		}
		started, err := d.InspectContainer(ctx, current.ID)
		if err != nil {
			return Container{}, created, err
		}
		current = &started
	}
	return *current, created, nil
}

func (s SystemContainer) create(ctx context.Context, d Client, hash string, log *slog.Logger) (*Container, error) {
	for _, v := range s.Volumes {
		labels := ManagedLabels(serverOf(s.Spec))
		labels[names.LabelVolume] = "system-" + v
		labels[names.LabelSystem] = s.Component
		if err := d.CreateVolume(ctx, VolumeCreate{Name: v, Labels: labels}); err != nil {
			return nil, err
		}
	}
	image := s.Spec.Config.Image
	if s.Pull {
		present, err := d.ImagePresent(ctx, image)
		if err != nil {
			return nil, err
		}
		if !present {
			log.Info("pulling system image", "component", s.Component, "image", image)
			if err := d.PullImage(ctx, image); err != nil {
				return nil, err
			}
		}
	}
	cc := *s.Spec
	cfg := *cc.Config
	cfg.Labels = make(map[string]string, len(s.Spec.Config.Labels)+1)
	for k, v := range s.Spec.Config.Labels {
		cfg.Labels[k] = v
	}
	cfg.Labels[names.LabelSpecHash] = hash
	cc.Config = &cfg
	id, err := d.CreateContainer(ctx, &cc)
	if err != nil {
		return nil, err
	}
	if s.Install != nil {
		if err := s.Install(ctx, id); err != nil {
			_ = d.RemoveContainer(ctx, id)
			return nil, fmt.Errorf("%s: install: %w", s.Component, err)
		}
	}
	c, err := d.InspectContainer(ctx, id)
	if err != nil {
		return nil, err
	}
	return &c, nil
}

func serverOf(cc *ContainerCreate) string { return cc.Config.Labels[names.LabelServer] }
