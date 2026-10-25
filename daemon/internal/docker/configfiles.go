package docker

import (
	"archive/tar"
	"bytes"
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// configFileMode is the mode of every config file: read-only for everyone.
const configFileMode = 0o444

// ConfigFilesTar renders config files as a tar archive to extract at "/":
// regular files only (no directory entries, so existing directories keep
// their owner and mode; Docker creates missing parents), mode 0444, owner
// 0:0, a fixed modification time. The files are validated first.
func ConfigFilesTar(files []state.ConfigFile, mounts []state.Mount) ([]byte, error) {
	if err := state.CheckConfigFiles(files, mounts); err != nil {
		return nil, fmt.Errorf("docker: config files: %w", err)
	}
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	for _, f := range files {
		hdr := &tar.Header{
			Typeflag: tar.TypeReg,
			Name:     strings.TrimPrefix(string(f.Path), "/"),
			Mode:     configFileMode,
			Size:     int64(len(f.Content)),
			ModTime:  time.Unix(0, 0).UTC(),
			Format:   tar.FormatPAX,
		}
		if err := tw.WriteHeader(hdr); err != nil {
			return nil, fmt.Errorf("docker: config files: %w", err)
		}
		if _, err := tw.Write([]byte(f.Content)); err != nil {
			return nil, fmt.Errorf("docker: config files: %w", err)
		}
	}
	if err := tw.Close(); err != nil {
		return nil, fmt.Errorf("docker: config files: %w", err)
	}
	return buf.Bytes(), nil
}

// writeConfigFiles extracts a created container's config files at "/"
// before it starts: a regular file never replaces a directory
// (noOverwriteDirNonDir) and owners come from the archive (0:0).
func (e *Engine) writeConfigFiles(ctx context.Context, id string, cc *ContainerCreate) error {
	var mounts []state.Mount
	for _, m := range cc.HostConfig.Mounts {
		mounts = append(mounts, state.Mount{Target: state.ContainerPath(m.Target)})
	}
	archive, err := ConfigFilesTar(cc.Files, mounts)
	if err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err = e.api.CopyToContainer(ctx, id, client.CopyToContainerOptions{DestinationPath: "/", Content: bytes.NewReader(archive)})
	return wrap("write config files into "+cc.Name, err)
}
