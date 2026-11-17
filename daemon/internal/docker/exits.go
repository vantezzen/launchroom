package docker

import (
	"context"
	"errors"
	"net"
	"sort"
	"strconv"
	"strings"
	"syscall"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"
)

// Exit and image facts (docs/03 §9, docs/15 §6): what launchroomd needs to
// say why a container stopped running or could not run at all.

// ExitResult is what Docker's wait returns when a container's process exits.
type ExitResult struct {
	Code int
	// Error is Docker's own error for the exit (e.g. the restart manager
	// could not start the process again), if any.
	Error string
}

// ExitOps waits for container exits (the reconciler's exit watcher).
type ExitOps interface {
	// WaitNextExit blocks until the container's process exits the next time
	// (POST /containers/{id}/wait?condition=next-exit, which dockerd-guard
	// allows) or ctx ends. Docker answers before its restart policy starts
	// the container again, so an inspection right after still sees the exit
	// code and the OOM flag of that run.
	WaitNextExit(ctx context.Context, id string) (ExitResult, error)
}

// ImageConfig is what an image's config says about how it runs.
type ImageConfig struct {
	// User is Config.User as written in the image.
	User string
	// ExposedPorts are the image's EXPOSEd TCP ports, sorted and unique.
	ExposedPorts []int
	// OS, Architecture and Variant are the image's platform ("linux",
	// "amd64", "v8"); empty when the image doesn't say.
	OS, Architecture, Variant string
}

// ImageOps reads image configs.
type ImageOps interface {
	ImageConfig(ctx context.Context, ref string) (ImageConfig, error)
}

var (
	_ ExitOps  = (*Engine)(nil)
	_ ImageOps = (*Engine)(nil)
)

// WaitNextExit implements ExitOps. It is not bounded by the call timeout:
// it waits as long as the container runs.
func (e *Engine) WaitNextExit(ctx context.Context, id string) (ExitResult, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return ExitResult{}, err
	}
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	res := e.api.ContainerWait(ctx, id, client.ContainerWaitOptions{Condition: container.WaitConditionNextExit})
	select {
	case r := <-res.Result:
		out := ExitResult{Code: int(r.StatusCode)}
		if r.Error != nil {
			out.Error = r.Error.Message
		}
		return out, nil
	case err := <-res.Error:
		return ExitResult{}, wrap("wait container", err)
	case <-ctx.Done():
		return ExitResult{}, ctx.Err()
	}
}

// ImageConfig implements ImageOps.
func (e *Engine) ImageConfig(ctx context.Context, ref string) (ImageConfig, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ImageInspect(ctx, ref)
	if err != nil {
		return ImageConfig{}, wrap("inspect image", err)
	}
	out := ImageConfig{OS: res.Os, Architecture: res.Architecture, Variant: res.Variant}
	if cfg := res.Config; cfg != nil {
		out.User = cfg.User
		keys := make([]string, 0, len(cfg.ExposedPorts))
		for k := range cfg.ExposedPorts {
			keys = append(keys, k)
		}
		out.ExposedPorts = TCPPorts(keys)
	}
	return out, nil
}

// TCPPorts parses EXPOSE keys ("8080/tcp", "8080", "53/udp") into the
// sorted, unique TCP ports among them.
func TCPPorts(keys []string) []int {
	seen := map[int]bool{}
	var out []int
	for _, k := range keys {
		num, proto, _ := strings.Cut(k, "/")
		if proto != "" && !strings.EqualFold(proto, "tcp") {
			continue
		}
		p, err := strconv.Atoi(num)
		if err != nil || p < 1 || p > 65535 || seen[p] {
			continue
		}
		seen[p] = true
		out = append(out, p)
	}
	sort.Ints(out)
	return out
}

// NormalizeArch maps the architecture names Docker reports for the host
// (uname: "x86_64", "aarch64") to the names images use ("amd64", "arm64").
func NormalizeArch(arch string) string {
	switch a := strings.ToLower(strings.TrimSpace(arch)); a {
	case "x86_64", "x86-64", "amd64":
		return "amd64"
	case "aarch64", "arm64", "armv8", "armv8l":
		return "arm64"
	case "armv7l", "armv7", "armhf", "armv6l", "arm":
		return "arm"
	case "i386", "i686", "386":
		return "386"
	default:
		return a
	}
}

// IsUnreachable reports an error of talking to Docker at all (the socket
// is gone, dockerd or dockerd-guard doesn't answer), as opposed to Docker
// refusing or failing a request.
func IsUnreachable(err error) bool {
	if err == nil {
		return false
	}
	if client.IsErrConnectionFailed(err) || errors.Is(err, syscall.ECONNREFUSED) || errors.Is(err, syscall.ENOENT) && strings.Contains(err.Error(), "dial") {
		return true
	}
	var op *net.OpError
	if errors.As(err, &op) && op.Op == "dial" {
		return true
	}
	msg := strings.ToLower(err.Error())
	for _, s := range []string{"cannot connect to the docker daemon", "is the docker daemon running", "connection refused", "broken pipe", "connection reset by peer"} {
		if strings.Contains(msg, s) {
			return true
		}
	}
	return false
}
