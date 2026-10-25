//go:build integration

package integration

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/inspect"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// plantShell runs inside the test container to plant what an attacker might
// leave behind (a constant, so TestNoShellInvocations sees no host shell call).
const plantShell = "sh"

// TestInspectContainer (docs/22 §7.2): through dockerd-guard's changes, top
// and archive HEAD routes, an inspection sees a file created by exec in
// /tmp (executable, flagged) and a sleep process started next to the entry
// process; a secret in a process command is masked; the result is a valid
// completion.
func TestInspectContainer(t *testing.T) {
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })
	s := &sessionEnv{serverID: state.ServerID(randomID("srv"))}
	if s.key, err = ecdh.X25519().GenerateKey(rand.Reader); err != nil {
		t.Fatal(err)
	}
	if s.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(s.serverID), UserAgent: "launchroom-itest"}); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()

	wl := s.start(ctx, t, busyboxImage, nil, []string{"sleep", "3600"})
	cs, err := s.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if err != nil || len(cs) != 1 {
		t.Fatalf("containers %v %v", cs, err)
	}
	id := cs[0].ID
	// What an attacker might leave behind: a dropped executable and a
	// background process with a token on its command line.
	execIn(ctx, t, api, id, plantShell, "-c", "mkdir -p /tmp/.x && printf '#!/bin/sh\\n' > /tmp/.x/kworker && chmod +x /tmp/.x/kworker && echo hi > /etc/motd")
	execDetached(ctx, t, api, id, plantShell, "-c", "sleep 1234; echo itest-secret-value-42")
	time.Sleep(500 * time.Millisecond)

	spec := &state.InspectContainerSpec{Workload: wl, MaskEnv: []state.EnvName{"API_TOKEN"}}
	res, err := inspect.Inspect(ctx, inspect.Options{
		Docker: s.engine,
		Workload: func(w state.WorkloadID) *state.WorkloadSpec {
			if w != wl {
				return nil
			}
			return &state.WorkloadSpec{ID: wl, Image: state.ImageRef(busyboxImage)}
		},
		Env: func(*state.WorkloadSpec) (map[string]secret.Secret, error) {
			return map[string]secret.Secret{"API_TOKEN": "itest-secret-value-42"}, nil
		},
	}, spec)
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("inspection: image %s matches=%v, %d changes, %d processes", res.ImageID, res.ImageMatches, res.Changes.Total, res.Processes.Total)
	if res.ContainerID != id || !res.ImageMatches || !strings.HasPrefix(res.ImageID, "sha256:") {
		t.Errorf("container facts: %+v", res)
	}
	var kworker *state.ContainerChange
	paths := []state.InspectedPath{}
	for i, c := range res.Changes.Entries {
		paths = append(paths, c.Path)
		if c.Path == "/tmp/.x/kworker" {
			kworker = &res.Changes.Entries[i]
		}
	}
	if kworker == nil || !kworker.Executable || kworker.Type != state.FileRegular || !slices.Contains(kworker.Flags, state.ChangeFlagExecutable) || !slices.Contains(kworker.Flags, state.ChangeFlagTempDir) {
		t.Errorf("kworker = %+v (paths %v)", kworker, paths)
	}
	if !slices.Contains(paths, "/etc/motd") {
		t.Errorf("/etc/motd missing: %v", paths)
	}
	var sleeps []state.ContainerProcess
	masked := false
	for _, p := range res.Processes.Entries {
		masked = masked || strings.Contains(p.Command, "echo ***")
		t.Logf("process %d (parent %d, %s): %q %v", p.PID, p.PPID, p.User, p.Command, p.Flags)
		if strings.HasPrefix(p.Command, "sleep ") {
			sleeps = append(sleeps, p)
		}
		if strings.Contains(p.Command, "itest-secret-value-42") {
			t.Errorf("unmasked command: %q", p.Command)
		}
	}
	if len(sleeps) != 2 || !masked {
		t.Fatalf("sleep processes = %+v, masked shell command %v", sleeps, masked)
	}
	done := state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, ContainerInspection: res}
	if err := done.Validate(); err != nil {
		t.Fatalf("completion invalid: %v", err)
	}

	// A stopped container fails the task.
	if err := s.engine.StopContainer(ctx, id, time.Second); err != nil {
		t.Fatal(err)
	}
	if _, err := inspect.Inspect(ctx, inspect.Options{Docker: s.engine, Workload: func(state.WorkloadID) *state.WorkloadSpec {
		return &state.WorkloadSpec{ID: wl, Image: state.ImageRef(busyboxImage)}
	}}, &state.InspectContainerSpec{Workload: wl}); err == nil || !strings.Contains(err.Error(), "isn't running") {
		t.Errorf("stopped container: %v", err)
	}
}

// execDetached starts argv in the container without waiting.
func execDetached(ctx context.Context, t *testing.T, api *client.Client, id string, argv ...string) {
	t.Helper()
	res, err := api.ExecCreate(ctx, id, client.ExecCreateOptions{Cmd: argv})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := api.ExecStart(ctx, res.ID, client.ExecStartOptions{Detach: true}); err != nil {
		t.Fatal(err)
	}
}
