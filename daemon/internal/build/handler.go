package build

import (
	"context"
	"crypto/ecdh"
	"errors"
	"fmt"

	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// Runner runs a build or plan job (*Builder).
type Runner interface {
	Run(ctx context.Context, job Job) (*Outcome, error)
}

// HandlerOptions configures Handler.
type HandlerOptions struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Builder    Runner
	Client     tasks.Client
	// Slots bounds concurrent builds and plans (build slots per server).
	Slots chan struct{}
	// Forget drops cached registry tokens of a finished task (optional).
	Forget func(state.TaskID)
}

// Handler returns the task handler for "build" and "plan" tasks: it opens
// the sealed task secrets, waits for a build slot, runs the job and ships
// its logs.
func Handler(o HandlerOptions) tasks.Handler {
	return func(ctx context.Context, t state.Task) (state.TaskResults, error) {
		var inputs *state.BuildInputs
		var image state.ImageRepository
		inspect := false
		switch spec := t.Spec.(type) {
		case *state.BuildSpec:
			inputs, image = &spec.BuildInputs, spec.Image
		case *state.PlanSpec:
			inputs, inspect = &spec.BuildInputs, spec.Inspect
		default:
			return state.TaskResults{}, errors.New("build handler got a non-build spec")
		}
		if o.Forget != nil {
			defer o.Forget(t.ID)
		}
		var token secret.Secret
		if inputs.CloneToken != nil {
			tok, err := sealed.OpenTaskToken(*inputs.CloneToken, o.SealingKey, o.ServerID, t.ID, state.PurposeClone)
			if err != nil {
				return state.TaskResults{}, &Error{Class: state.FailureConfig, Step: "setup", Err: fmt.Errorf("clone token: %w", err)}
			}
			token = tok
		}
		var sshKey secret.Secret
		if inputs.SSHKey != nil {
			key, err := sealed.OpenTaskToken(*inputs.SSHKey, o.SealingKey, o.ServerID, t.ID, state.PurposeSSHKey)
			if err != nil {
				return state.TaskResults{}, &Error{Class: state.FailureConfig, Step: "setup", Err: fmt.Errorf("deploy key: %w", err)}
			}
			sshKey = key
		}
		env := map[string]secret.Secret{}
		if inputs.Env != nil {
			e, err := sealed.OpenTaskEnv(*inputs.Env, o.SealingKey, o.ServerID, t.ID)
			if err != nil {
				return state.TaskResults{}, &Error{Class: state.FailureConfig, Step: "setup", Err: fmt.Errorf("build env: %w", err)}
			}
			env = e
		}

		logs := tasks.NewTaskLogShipper(ctx, o.Client, t.ID, t.Attempt)
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
					return state.TaskResults{}, &Error{Class: state.FailureTimeout, Step: "queue", Err: ctx.Err()}
				}
			}
		}

		out, err := o.Builder.Run(ctx, Job{Task: t.ID, Inputs: *inputs, Image: image, CloneToken: token, SSHKey: sshKey, Env: env, Sink: logs, Inspect: inspect})
		if err != nil {
			// A failed plan still reports what it read (docs/20 §5).
			var be *Error
			if errors.As(err, &be) {
				return state.TaskResults{Inspection: be.Inspection}, err
			}
			return state.TaskResults{}, err
		}
		ms := out.Duration.Milliseconds()
		if image == "" {
			return state.TaskResults{Plan: &state.PlanResult{DurationMs: ms, Summary: out.Summary}, Inspection: out.Inspection}, nil
		}
		s := out.Summary
		return state.TaskResults{Build: &state.BuildResult{
			Image: out.Image, DurationMs: ms, Summary: &s, SecretsHash: out.SecretsHash, Attestations: out.Attestations,
		}}, nil
	}
}
