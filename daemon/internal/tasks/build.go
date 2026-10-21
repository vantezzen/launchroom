package tasks

import (
	"context"
	"crypto/ecdh"
	"errors"
	"fmt"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Runner (builds, plans) and the build pipeline meet here: open the sealed
// task secrets, wait for a build slot, run, stream logs, map the outcome.

// Builder runs a build or plan job (build.Builder).
type Builder interface {
	Run(ctx context.Context, job build.Job) (*build.Outcome, error)
}

// BuildOptions configures BuildHandler.
type BuildOptions struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Builder    Builder
	Client     Client
	// Slots bounds concurrent builds and plans (build slots per server).
	Slots chan struct{}
	// Forget drops cached registry tokens of a finished task (optional).
	Forget func(state.TaskID)
}

// failureCarrier is implemented by classified build errors (*build.Error).
type failureCarrier interface {
	Failure() *state.TaskFailure
}

// BuildHandler returns the handler for "build" and "plan" tasks.
func BuildHandler(o BuildOptions) Handler {
	return func(ctx context.Context, t state.Task) (Result, error) {
		var inputs *state.BuildInputs
		var image state.ImageRepository
		inspect := false
		switch spec := t.Spec.(type) {
		case *state.BuildSpec:
			inputs, image = &spec.BuildInputs, spec.Image
		case *state.PlanSpec:
			inputs, inspect = &spec.BuildInputs, spec.Inspect
		default:
			return Result{}, errors.New("build handler got a non-build spec")
		}
		if o.Forget != nil {
			defer o.Forget(t.ID)
		}
		var token secret.Secret
		if inputs.CloneToken != nil {
			tok, err := sealed.OpenTaskToken(*inputs.CloneToken, o.SealingKey, o.ServerID, t.ID, state.PurposeClone)
			if err != nil {
				return Result{}, &build.Error{Class: state.FailureConfig, Step: "setup", Err: fmt.Errorf("clone token: %w", err)}
			}
			token = tok
		}
		var sshKey secret.Secret
		if inputs.SSHKey != nil {
			key, err := sealed.OpenTaskToken(*inputs.SSHKey, o.SealingKey, o.ServerID, t.ID, state.PurposeSSHKey)
			if err != nil {
				return Result{}, &build.Error{Class: state.FailureConfig, Step: "setup", Err: fmt.Errorf("deploy key: %w", err)}
			}
			sshKey = key
		}
		env := map[string]secret.Secret{}
		if inputs.Env != nil {
			e, err := sealed.OpenTaskEnv(*inputs.Env, o.SealingKey, o.ServerID, t.ID)
			if err != nil {
				return Result{}, &build.Error{Class: state.FailureConfig, Step: "setup", Err: fmt.Errorf("build env: %w", err)}
			}
			env = e
		}

		logs := newStepShipper(ctx, o.Client, state.LogSource{Kind: state.LogSourceTask, ID: string(t.ID)}, t.Attempt)
		defer logs.Close()

		if o.Slots != nil {
			select {
			case o.Slots <- struct{}{}:
				defer func() { <-o.Slots }()
			default:
				logs.Line("queue", state.StreamStdout, "Waiting for a free build slot on this server") //nolint:contextcheck // logs ship on the shipper's own context
				select {
				case o.Slots <- struct{}{}:
					defer func() { <-o.Slots }()
				case <-ctx.Done():
					return Result{}, &build.Error{Class: state.FailureTimeout, Step: "queue", Err: ctx.Err()}
				}
			}
		}

		out, err := o.Builder.Run(ctx, build.Job{Task: t.ID, Inputs: *inputs, Image: image, CloneToken: token, SSHKey: sshKey, Env: env, Sink: logs, Inspect: inspect})
		if err != nil {
			// A failed plan still reports what it read (docs/20 §5).
			var be *build.Error
			if errors.As(err, &be) {
				return Result{Inspection: be.Inspection}, err
			}
			return Result{}, err
		}
		ms := out.Duration.Milliseconds()
		if image == "" {
			return Result{Plan: &state.PlanResult{DurationMs: ms, Summary: out.Summary}, Inspection: out.Inspection}, nil
		}
		s := out.Summary
		return Result{Build: &state.BuildResult{
			Image: out.Image, DurationMs: ms, Summary: &s, SecretsHash: out.SecretsHash, Attestations: out.Attestations,
		}}, nil
	}
}

// stepShipper batches step-grouped build log lines and ships them live
// (every second, or every logBatchLines lines). Seq starts at a per-attempt
// offset so a retried task's lines do not collide with the first attempt's
// (the dashboard ignores chunks it already has for a (source, seq)).
type stepShipper struct {
	client Client
	source state.LogSource
	ctx    context.Context

	mu    sync.Mutex
	lines []state.LogLine
	seq   uint64
	stop  chan struct{}
	done  chan struct{}
	once  sync.Once
}

// attemptSeqBase separates the log seq ranges of task attempts.
const attemptSeqBase = 1_000_000_000

func newStepShipper(ctx context.Context, c Client, src state.LogSource, attempt int) *stepShipper {
	s := &stepShipper{client: c, source: src, ctx: context.WithoutCancel(ctx), stop: make(chan struct{}), done: make(chan struct{})}
	if attempt > 1 {
		s.seq = uint64(attempt-1) * attemptSeqBase //nolint:gosec // attempt >= 1
	}
	go s.loop() //nolint:contextcheck // the loop owns a detached context so the last lines still ship on cancel
	return s
}

func (s *stepShipper) loop() {
	defer close(s.done)
	t := time.NewTicker(logFlushEvery)
	defer t.Stop()
	for {
		select {
		case <-s.stop:
			s.flush()
			return
		case <-t.C:
			s.flush()
		}
	}
}

// Line implements build.LogSink.
func (s *stepShipper) Line(step string, stream state.LogStream, text string) {
	if len(step) > 128 {
		step = step[:128]
	}
	s.mu.Lock()
	s.lines = append(s.lines, state.LogLine{Time: time.Now().UTC(), Stream: stream, Text: sanitizeLine(text), Step: step})
	full := len(s.lines) >= logBatchLines
	s.mu.Unlock()
	if full {
		s.flush()
	}
}

func (s *stepShipper) flush() {
	s.mu.Lock()
	if len(s.lines) == 0 {
		s.mu.Unlock()
		return
	}
	s.seq++
	batch := &state.LogBatch{
		TypeMeta: state.NewTypeMeta(state.KindLogBatch),
		Chunks:   []state.LogChunk{{Source: s.source, Seq: s.seq, Lines: s.lines}},
	}
	s.lines = nil
	s.mu.Unlock()
	ctx, cancel := context.WithTimeout(s.ctx, 30*time.Second)
	defer cancel()
	_ = s.client.ShipLogs(ctx, batch) // logs are best effort; the build result is authoritative
}

// Close flushes the remaining lines and stops the loop.
func (s *stepShipper) Close() {
	s.once.Do(func() { close(s.stop) })
	<-s.done
}
