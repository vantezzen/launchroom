package docker

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"

	"github.com/moby/moby/api/types/network"
	"github.com/moby/moby/client"
)

// AmbassadorOps are the Engine operations the ambassador system container
// needs on top of SystemOps (docs/05 §3): building its image locally from
// launchroomd's own binary, and attaching it to environment networks with
// network aliases while it runs.
type AmbassadorOps interface {
	SystemOps
	// ImportImage creates the image ref ("lr-…:tag") from a root
	// filesystem tar with the given Dockerfile-style changes.
	ImportImage(ctx context.Context, ref string, rootfs io.Reader, changes []string) error
	// ConnectNetwork attaches a managed container to a managed network with aliases.
	ConnectNetwork(ctx context.Context, networkName, containerID string, aliases []string) error
	// DisconnectNetwork detaches a managed container from a managed network.
	DisconnectNetwork(ctx context.Context, networkName, containerID string) error
}

var _ AmbassadorOps = (*Engine)(nil)

// ImportImage implements AmbassadorOps.
func (e *Engine) ImportImage(ctx context.Context, ref string, rootfs io.Reader, changes []string) error {
	if !strings.HasPrefix(ref, "lr-") {
		return fmt.Errorf("docker: image name %q must be generated (lr-…)", ref)
	}
	ctx, cancel := context.WithTimeout(ctx, e.pullTimeout)
	defer cancel()
	res, err := e.api.ImageImport(ctx, client.ImageImportSource{Source: rootfs, SourceName: "-"}, ref, client.ImageImportOptions{Changes: changes})
	if err != nil {
		return wrap("import image "+ref, err)
	}
	defer func() { _ = res.Close() }()
	// The answer is a JSON message stream; an error is reported in it.
	dec := json.NewDecoder(res)
	for {
		var m struct {
			Error       string `json:"error"`
			ErrorDetail struct {
				Message string `json:"message"`
			} `json:"errorDetail"`
		}
		if err := dec.Decode(&m); err != nil {
			if errors.Is(err, io.EOF) {
				return nil
			}
			return wrap("import image "+ref, err)
		}
		if m.Error != "" || m.ErrorDetail.Message != "" {
			return fmt.Errorf("docker: import image %s: %s%s", ref, m.Error, m.ErrorDetail.Message)
		}
	}
}

// managedNetworkID resolves a managed network by name.
func (e *Engine) managedNetworkID(ctx context.Context, name string) (string, error) {
	nets, err := e.ListNetworks(ctx)
	if err != nil {
		return "", err
	}
	for _, n := range nets {
		if n.Name == name {
			return n.ID, nil
		}
	}
	return "", fmt.Errorf("docker: network %s: %w", name, ErrNotFound)
}

// ConnectNetwork implements AmbassadorOps.
func (e *Engine) ConnectNetwork(ctx context.Context, networkName, containerID string, aliases []string) error {
	if err := e.mustOwnContainer(ctx, containerID); err != nil {
		return err
	}
	id, err := e.managedNetworkID(ctx, networkName)
	if err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err = e.api.NetworkConnect(ctx, id, client.NetworkConnectOptions{Container: containerID, EndpointConfig: &network.EndpointSettings{Aliases: aliases}})
	return wrap("connect network "+networkName, err)
}

// DisconnectNetwork implements AmbassadorOps.
func (e *Engine) DisconnectNetwork(ctx context.Context, networkName, containerID string) error {
	if err := e.mustOwnContainer(ctx, containerID); err != nil {
		return err
	}
	id, err := e.managedNetworkID(ctx, networkName)
	if err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err = e.api.NetworkDisconnect(ctx, id, client.NetworkDisconnectOptions{Container: containerID, Force: true})
	return wrap("disconnect network "+networkName, err)
}
