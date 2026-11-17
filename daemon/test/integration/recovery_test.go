//go:build integration

package integration

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/session"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// G2 (OD9): a recovery shell for a container that never stays up: the
// workload's image, variables and volumes with an idle program instead of
// the app; the volume is read-only, and the container is gone afterwards.
func TestRecoverySession(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	w := e.busyboxWorkload(t, wl, busyboxImage, []string{"nope-not-here"}, false)
	w.Mounts = []state.Mount{{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC20", Target: "/data"}}
	ns := e.lifecycleState(t, 1, "")
	ns.Workloads = []state.WorkloadSpec{w}
	ns.Volumes = []state.VolumeSpec{{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC20", Name: state.HostName("lr-v_rec-" + strings.ToLower(randomID("x"))[2:10])}}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	watchUntil(t, e, ns, time.Minute, func() bool {
		f := status(e.rec, wl).Failure
		return f != nil && f.Code == state.FailureCommandNotFound
	})

	relay := newFakeRelay(t)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()
	key := make([]byte, 32)
	_, _ = rand.Read(key)
	task := state.TaskID(randomID("tsk"))
	sealedKey, err := sealed.SealJSON(state.TaskToken{ServerID: e.serverID, TaskID: task, Purpose: state.PurposeSession, Token: secret.Secret(base64.RawURLEncoding.EncodeToString(key))}, e.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	sid := state.SessionID(randomID("ses"))
	spec := &state.SessionSpec{Session: sid, Kind: state.SessionRecovery, Workload: wl, Key: sealedKey, Channel: state.SessionChannelFor(sid), IdleTimeout: "5m", Shell: state.ShellSh, Cols: 80, Rows: 24}
	if err := spec.Validate(); err != nil {
		t.Fatal(err)
	}
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{policy.DefaultRegistry, "docker.io/library/"}
	h := session.Handler(session.Options{
		ServerID: e.serverID, SealingKey: e.key, Auth: authorizer{channel: spec.Channel},
		Nudge: func(context.Context) (*state.NudgeConfig, error) {
			return &state.NudgeConfig{Key: relayKey, URL: state.WebSocketURL(strings.Replace(relay.srv.URL, "http://", "ws://", 1))}, nil
		},
		Docker: e.engine,
		WorkloadEnv: func(state.WorkloadID) (map[string]secret.Secret, error) {
			return map[string]secret.Secret{"APP_SECRET": "s3cret-value"}, nil
		},
		InDesiredState: func(id state.WorkloadID) bool { return id == wl },
		Recovery:       &session.Recovery{NodeState: func() *state.NodeState { return ns }, Policy: pol},
		Logs:           logSink{},
		HelloWait:      30 * time.Second,
	})
	done, errc := make(chan state.TaskResults, 1), make(chan error, 1)
	go func() {
		res, err := h(ctx, state.Task{ID: task, Type: state.TaskSession, Attempt: 1, Deadline: time.Now().Add(10 * time.Minute), Spec: spec})
		done <- res
		errc <- err
	}()
	b := dialBrowser(ctx, t, relay, key, sid)
	b.await(session.TReady, nil)
	b.send(ctx, session.Payload{T: session.THello, Cols: 80, Rows: 24})
	if p := b.await(session.TStarted, nil); p.Shell != "/bin/sh" {
		t.Fatalf("shell %q", p.Shell)
	}
	var out strings.Builder
	collect := func(p session.Payload) {
		if p.T == session.TOut {
			d, _ := session.DecodeData(p.D)
			out.Write(d)
		}
	}
	b.send(ctx, session.Payload{T: session.TIn, D: session.EncodeData([]byte("echo secret-$APP_SECRET; touch /data/x || echo ro-ok; cat /proc/1/cmdline | tr '\\0' ' '; echo; exit 0\n"))})
	b.await(session.TExit, collect)
	got := out.String()
	if !strings.Contains(got, "secret-s3cret-value") || !strings.Contains(got, "ro-ok") || !strings.Contains(got, "Read-only file system") {
		t.Fatalf("output %q", got)
	}
	b.await(session.TEnd, nil)
	<-done
	if err := <-errc; err != nil {
		t.Fatal(err)
	}
	cs, err := e.engine.ListContainers(t.Context(), nil)
	if err != nil {
		t.Fatal(err)
	}
	for _, c := range cs {
		if strings.HasPrefix(c.Name, session.RecoveryPrefix) {
			t.Fatalf("recovery container left: %s", c.Name)
		}
	}
	// The workload itself is untouched (still the crashing container).
	if c, err := docker.WorkloadContainer(t.Context(), e.engine, wl); err != nil || c == nil || c.Name != names.WorkloadContainer(wl) {
		t.Fatalf("workload container: %+v %v", c, err)
	}
}
