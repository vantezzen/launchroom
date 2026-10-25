package session

import (
	"cmp"
	"context"
	"crypto/ecdh"
	"encoding/base64"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// Session timing defaults (docs/03 §11.3).
const (
	DefaultHelloWait = 30 * time.Second
	DefaultLiveness  = 45 * time.Second
	readyEvery       = time.Second
	flushAfter       = 15 * time.Millisecond
	inputRate        = 64 << 10
	inputBurst       = 256 << 10
	maxTranscript    = 1 << 20
	// TranscriptStep is the log step of recorded console output.
	TranscriptStep = "console"
)

// Docker is what sessions need from the Engine.
type Docker interface {
	docker.Client
	docker.Interactive
	docker.Execer
}

// Options configures Handler.
type Options struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	// Auth authorizes the relay socket on the session channel.
	Auth Authorizer
	// Nudge returns the dashboard's Pusher settings (nil: realtime is off).
	Nudge           func(ctx context.Context) (*state.NudgeConfig, error)
	ControlPlaneURL *url.URL
	// ControlPlaneBase, when set, wins over ControlPlaneURL (the client's
	// active base URL, docs/03 §4.2).
	ControlPlaneBase func() *url.URL
	// Trusted verifies signed dashboard commands.
	Trusted func() Verifier
	Docker  Docker
	// WorkloadEnv opens the sealed env of a workload in the verified NodeState.
	WorkloadEnv func(state.WorkloadID) (map[string]secret.Secret, error)
	// InDesiredState reports whether a workload is in the verified NodeState.
	InDesiredState func(state.WorkloadID) bool
	// Allowed is the local policy (LR_INTERACTIVE_SESSIONS).
	Allowed func(state.SessionKind) bool
	// Logs ships transcripts (task logs).
	Logs          tasks.Client
	DaemonVersion string
	HTTPClient    *http.Client
	Log           *slog.Logger
	Now           func() time.Time
	// HelloWait and Liveness override the browser timeouts (tests).
	HelloWait, Liveness time.Duration
}

// controlPlane is the base URL the session relay derives its endpoint from.
func (o *Options) controlPlane() *url.URL {
	if o.ControlPlaneBase != nil {
		if u := o.ControlPlaneBase(); u != nil {
			return u
		}
	}
	return o.ControlPlaneURL
}

func (o *Options) defaults() {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.HelloWait <= 0 {
		o.HelloWait = DefaultHelloWait
	}
	if o.Liveness <= 0 {
		o.Liveness = DefaultLiveness
	}
	if o.Allowed == nil {
		o.Allowed = func(state.SessionKind) bool { return true }
	}
}

// ErrRefused marks failures of the local policy.
var ErrRefused = errors.New("refused by this server's policy")

// Handler returns the tasks.Handler for "session" tasks.
func Handler(o Options) tasks.Handler {
	o.defaults()
	return func(ctx context.Context, t state.Task) (tasks.Result, error) {
		spec, ok := t.Spec.(*state.SessionSpec)
		if !ok {
			return tasks.Result{}, errors.New("session handler got a non-session spec")
		}
		res := &state.SessionResult{EndReason: state.EndError}
		out := tasks.Result{Session: res}
		if !o.Allowed(spec.Kind) {
			res.EndReason = state.EndRefused
			return out, fmt.Errorf("%s sessions are turned off on this server (LR_INTERACTIVE_SESSIONS)", spec.Kind)
		}
		tok, err := sealed.OpenTaskToken(spec.Key, o.SealingKey, o.ServerID, t.ID, state.PurposeSession)
		if err != nil {
			return out, fmt.Errorf("session key: %w", err)
		}
		key, err := base64.RawURLEncoding.DecodeString(tok.Reveal())
		if err != nil || len(key) != 32 {
			return out, errors.New("session key: not 32 bytes of base64url")
		}
		s := &run{o: o, t: t, spec: spec, res: res, codec: NewCodec(key, spec.Session, true), log: o.Log.With("session", spec.Session, "kind", spec.Kind)}
		if err := s.prepare(ctx); err != nil {
			if errors.Is(err, ErrRefused) {
				res.EndReason = state.EndRefused
			}
			return out, err
		}
		defer s.cleanup()
		var cfg *state.NudgeConfig
		if o.Nudge != nil {
			if cfg, err = o.Nudge(ctx); err != nil {
				return out, fmt.Errorf("session: realtime settings: %w", err)
			}
		}
		relay, err := Dial(ctx, RelayOptions{
			Nudge: cfg, ControlPlaneURL: o.controlPlane(), Channel: spec.Channel, Auth: o.Auth,
			DaemonVersion: o.DaemonVersion, HTTPClient: o.HTTPClient,
		})
		if err != nil {
			return out, err
		}
		s.relay = relay
		defer relay.Close()
		var trusted Verifier
		if o.Trusted != nil {
			trusted = o.Trusted()
		}
		s.guard = newCommandGuard(trusted, spec.Session, o.Now)
		s.loop(ctx)
		s.log.Info("session ended", "reason", res.EndReason)
		// A session that couldn't start fails its task with the reason, so
		// the dashboard can show and log it (the browser got it in the end frame).
		return out, s.failure
	}
}

// run is one session.
type run struct {
	o     Options
	t     state.Task
	spec  *state.SessionSpec
	res   *state.SessionResult
	codec *Codec
	guard *commandGuard
	relay *Relay
	log   *slog.Logger
	// failure is why the session couldn't start (nil otherwise).
	failure error

	sendMu sync.Mutex

	// console
	container string
	shell     string
	tty       *docker.TTYExec
	output    chan []byte
	exited    chan struct{}
	stopped   chan struct{}
	pending   []byte
	recorder  *recorder

	// data
	engine   *engineRunner
	commands chan *state.SessionCommand
	workerWG sync.WaitGroup
	activity chan struct{}
	resMu    sync.Mutex
}

// prepare applies the local policy and resolves the target before any
// connection is made.
func (s *run) prepare(ctx context.Context) error {
	id, err := s.target(ctx)
	if err != nil {
		return err
	}
	s.container = id
	switch s.spec.Kind {
	case state.SessionConsole:
		shell, err := s.pickShell(ctx)
		if err != nil {
			return err
		}
		s.shell = shell
		if s.spec.Record {
			env, _ := s.o.WorkloadEnv(s.spec.Workload)
			s.recorder = newRecorder(tasks.NewTaskLogShipper(ctx, s.o.Logs, s.t.ID, s.t.Attempt), maskerFor(env))
		}
	case state.SessionData:
		env, err := s.o.WorkloadEnv(s.spec.Workload)
		if err != nil {
			return fmt.Errorf("database credentials: %w", err)
		}
		eng, err := newEngineRunner(s.spec.Engine, env, s.o.Docker, id)
		if err != nil {
			return err
		}
		s.engine = eng
	}
	return nil
}

// target finds the running container of the spec's workload (docs/03 §11.4).
func (s *run) target(ctx context.Context) (string, error) {
	wl := s.spec.Workload
	if s.o.InDesiredState == nil || !s.o.InDesiredState(wl) {
		return "", fmt.Errorf("%w: workload %s is not in this server's desired state", ErrRefused, wl)
	}
	cs, err := s.o.Docker.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if err != nil {
		return "", err
	}
	stopped := false
	for _, c := range cs {
		if !eligible(c, wl) {
			continue
		}
		// A stopped duplicate (a restart or a recreate in progress) never
		// hides the running container.
		if c.Running {
			return c.ID, nil
		}
		stopped = true
	}
	if stopped {
		return "", fmt.Errorf("the container of %s is not running", wl)
	}
	return "", fmt.Errorf("%w: no workload container for %s on this server", ErrRefused, wl)
}

// eligible: a managed workload container, never a system, task or helper container.
func eligible(c docker.Container, wl state.WorkloadID) bool {
	l := c.Labels
	return l[docker.LabelManaged] == "true" && l[docker.LabelWorkload] == string(wl) &&
		l[docker.LabelSystem] == "" && l[docker.LabelTask] == "" && l[docker.LabelHelper] == "" &&
		!strings.HasPrefix(c.Name, "lr-system-")
}

// autoShells is the automatic order (docs/03 §11.4): bash, then the
// busybox/Alpine ash, then plain sh.
var autoShells = []state.ShellPath{state.ShellBash, state.ShellAsh, state.ShellSh}

// errNoShell is the start failure of an image without any shell.
var errNoShell = errors.New("this image has no shell (looked for /bin/bash, /bin/ash and /bin/sh)")

func (s *run) pickShell(ctx context.Context) (string, error) {
	if s.spec.Shell != "" {
		if err := s.spec.Shell.Validate(); err != nil {
			return "", fmt.Errorf("%w: %w", ErrRefused, err)
		}
		ok, err := s.o.Docker.PathExists(ctx, s.container, string(s.spec.Shell))
		if err != nil {
			return "", fmt.Errorf("checking for %s in the container: %w", s.spec.Shell, err)
		}
		if !ok {
			return "", fmt.Errorf("this image has no %s", s.spec.Shell)
		}
		return string(s.spec.Shell), nil
	}
	// A failed stat of one candidate (an engine quirk, a refusal) doesn't
	// stop the search: the next shell may still be found.
	var statErr error
	for _, sh := range autoShells {
		ok, err := s.o.Docker.PathExists(ctx, s.container, string(sh))
		if err != nil {
			s.logger().Warn("checking for a shell failed", "shell", sh, "err", err)
			statErr = cmp.Or(statErr, err)
			continue
		}
		if ok {
			return string(sh), nil
		}
	}
	if statErr != nil {
		return "", fmt.Errorf("checking for a shell in the container: %w", statErr)
	}
	return "", errNoShell
}

func (s *run) cleanup() {
	if s.stopped != nil {
		close(s.stopped)
	}
	if s.tty != nil {
		_ = s.tty.Close()
	}
	if s.commands != nil {
		close(s.commands)
		s.workerWG.Wait()
	}
	if s.recorder != nil {
		s.recorder.close()
	}
}

// send seals and sends one payload (serialised: the main loop and the data
// worker both send).
func (s *run) send(ctx context.Context, p Payload) error {
	s.sendMu.Lock()
	defer s.sendMu.Unlock()
	f, err := s.codec.Seal(p)
	if err != nil {
		return err
	}
	return s.relay.SendFrame(ctx, f)
}

func (s *run) end(ctx context.Context, reason state.SessionEndReason) {
	s.endWith(ctx, reason, "")
}

// endWith ends the session and tells the browser why, with an optional
// human-readable message (start failures).
func (s *run) endWith(ctx context.Context, reason state.SessionEndReason, message string) {
	s.res.EndReason = reason
	sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 3*time.Second)
	defer cancel()
	s.flushOutput(sctx)
	_ = s.send(sctx, Payload{T: TEnd, Reason: string(reason), Message: clip(message)})
}

func (s *run) logger() *slog.Logger {
	if s.log != nil {
		return s.log
	}
	return slog.New(slog.DiscardHandler)
}

// loop runs the session until it ends; it sets res.EndReason.
func (s *run) loop(ctx context.Context) {
	now := s.o.Now
	connectedAt := now()
	var helloed bool
	lastSeen := now()
	lastActivity := now()
	idle := s.spec.IdleTimeout.Std()
	if idle <= 0 {
		idle = 15 * time.Minute
	}
	bad := 0
	in := newBucket(inputRate, inputBurst, now())
	tick := time.NewTicker(readyEvery)
	defer tick.Stop()
	flush := time.NewTimer(time.Hour)
	flush.Stop()
	s.activity = make(chan struct{}, 1)
	_ = s.send(ctx, Payload{T: TReady, Kind: string(s.spec.Kind)})

	for {
		select {
		case <-ctx.Done():
			switch {
			case tasks.LeaseLost(ctx):
				s.end(ctx, state.EndError)
			case errors.Is(ctx.Err(), context.DeadlineExceeded):
				s.end(ctx, state.EndDeadline)
			default:
				s.end(ctx, state.EndEnded)
			}
			return
		case ev, ok := <-s.relay.Events():
			if !ok {
				s.log.Warn("relay connection lost", "err", s.relay.Err())
				s.res.EndReason = state.EndError
				return
			}
			switch ev.Name {
			case FrameEvent:
				p, err := s.codec.Open(ev.Data)
				if err != nil {
					bad++
					s.log.Debug("dropping frame", "err", err)
					if bad >= MaxBadFrames {
						s.end(ctx, state.EndError)
						return
					}
					continue
				}
				lastSeen = now()
				switch p.T {
				case THello:
					if helloed {
						continue
					}
					helloed = true
					if err := s.start(ctx, p); err != nil {
						s.log.Warn("session could not start", "err", err)
						s.failure = fmt.Errorf("starting the session: %w", err)
						s.endWith(ctx, state.EndError, s.failure.Error())
						return
					}
				case TIn:
					if s.tty == nil {
						continue
					}
					b, err := DecodeData(p.D)
					if err != nil {
						bad++
						continue
					}
					if !in.take(len(b), now()) {
						s.log.Warn("browser input over the rate limit")
						s.end(ctx, state.EndError)
						return
					}
					s.res.BytesIn += int64(len(b))
					lastActivity = now()
					if _, err := s.tty.Write(b); err != nil {
						s.end(ctx, state.EndExited)
						return
					}
				case TResize:
					if s.tty != nil && validSize(p.Cols, p.Rows) {
						_ = s.tty.Resize(ctx, uint(p.Cols), uint(p.Rows)) //nolint:gosec // validated range
					}
				case TPing:
					_ = s.send(ctx, Payload{T: TPong})
				case TBye:
					s.end(ctx, state.EndClosed)
					return
				}
			case state.SessionEventCommand, state.SessionEventEnd:
				cmd, err := s.guard.open(ev.Name, ev.Data)
				if err != nil {
					s.log.Warn("refusing a session command", "err", err)
					continue
				}
				if cmd.Command == state.CommandEnd {
					s.end(ctx, state.EndEnded)
					return
				}
				if s.engine == nil {
					continue
				}
				lastActivity = now()
				if s.res.StartedAt.IsZero() {
					s.res.StartedAt = now().UTC()
				}
				select {
				case s.commands <- cmd:
				default:
					s.recordCommand(state.SessionCommandResult{Op: cmd.Op, Status: state.CommandRefused})
					_ = s.sendResult(ctx, &Result{Op: cmd.Op, Status: state.CommandRefused, Message: "Too many commands are waiting; try again."})
				}
			}
		case b := <-s.outputCh():
			if b == nil {
				continue
			}
			s.pending = append(s.pending, b...)
			if len(s.pending) >= MaxData {
				s.flushOutput(ctx)
			} else {
				flush.Reset(flushAfter)
			}
		case <-flush.C:
			s.flushOutput(ctx)
		case <-s.exitedCh():
			for drained := false; !drained; {
				select {
				case b := <-s.output:
					s.pending = append(s.pending, b...)
				default:
					drained = true
				}
			}
			s.flushOutput(ctx)
			code, err := s.tty.ExitCode(context.WithoutCancel(ctx))
			if err == nil {
				s.res.ExitCode = &code
				_ = s.send(ctx, Payload{T: TExit, Code: &code})
			}
			if err == nil && shellFailedToStart(code, now().Sub(s.res.StartedAt)) {
				s.failure = fmt.Errorf("%s couldn't start in the container (exit code %d)", s.shell, code)
				s.endWith(ctx, state.EndExited, s.failure.Error())
				return
			}
			s.end(ctx, state.EndExited)
			return
		case <-s.activity:
			lastActivity = now()
		case <-tick.C:
			t := now()
			if !helloed {
				if t.Sub(connectedAt) > s.o.HelloWait {
					s.end(ctx, state.EndNeverConnected)
					return
				}
				_ = s.send(ctx, Payload{T: TReady, Kind: string(s.spec.Kind)})
				continue
			}
			if t.Sub(lastSeen) > s.o.Liveness {
				s.end(ctx, state.EndDisconnected)
				return
			}
			if t.Sub(lastActivity) > idle {
				s.end(ctx, state.EndIdle)
				return
			}
		}
	}
}

// shellFailedToStart: the runtime couldn't run the shell (126: not
// executable, 127: not found), seen as an exit right after the start.
func shellFailedToStart(code int, after time.Duration) bool {
	return (code == 126 || code == 127) && after < 3*time.Second
}

func validSize(cols, rows int) bool { return cols >= 2 && cols <= 500 && rows >= 2 && rows <= 200 }

func (s *run) outputCh() <-chan []byte {
	if s.output == nil {
		return nil
	}
	return s.output
}

func (s *run) exitedCh() <-chan struct{} {
	if s.exited == nil {
		return nil
	}
	return s.exited
}

// start begins the session after the browser's hello.
func (s *run) start(ctx context.Context, hello Payload) error {
	switch s.spec.Kind {
	case state.SessionConsole:
		cols, rows := s.spec.Cols, s.spec.Rows
		if validSize(hello.Cols, hello.Rows) {
			cols, rows = hello.Cols, hello.Rows
		}
		if !validSize(cols, rows) {
			cols, rows = 80, 24
		}
		tty, err := s.o.Docker.ExecTTY(ctx, s.container, docker.TTYOptions{
			Argv: []string{s.shell}, Env: []string{"TERM=xterm-256color"}, Cols: uint(cols), Rows: uint(rows), //nolint:gosec // validated range
		})
		if err != nil {
			return err
		}
		s.tty = tty
		s.res.StartedAt = s.o.Now().UTC()
		s.output = make(chan []byte, 64)
		s.exited = make(chan struct{})
		s.stopped = make(chan struct{})
		go s.pump()
		return s.send(ctx, Payload{T: TStarted, Shell: s.shell})
	case state.SessionData:
		s.commands = make(chan *state.SessionCommand, 8)
		s.workerWG.Add(1)
		go s.worker(context.WithoutCancel(ctx))
		return s.send(ctx, Payload{T: TStarted})
	}
	return nil
}

// pump copies terminal output to the loop.
func (s *run) pump() {
	defer close(s.exited)
	buf := make([]byte, MaxData)
	for {
		n, err := s.tty.Read(buf)
		if n > 0 {
			b := append([]byte(nil), buf[:n]...)
			select {
			case s.output <- b:
			case <-s.stopped:
				return
			}
		}
		if err != nil {
			return
		}
	}
}

func (s *run) flushOutput(ctx context.Context) {
	for len(s.pending) > 0 {
		n := min(len(s.pending), MaxData)
		chunk := s.pending[:n]
		if s.recorder != nil {
			s.recorder.write(chunk)
		}
		s.res.BytesOut += int64(n)
		_ = s.send(ctx, Payload{T: TOut, D: EncodeData(chunk)})
		s.pending = s.pending[n:]
	}
	s.pending = nil
}

// recorder turns terminal output into masked transcript lines.
type recorder struct {
	ship    tasks.LineShipper
	mask    *build.Masker
	partial []byte
	total   int
	cut     bool
}

func newRecorder(ship tasks.LineShipper, mask *build.Masker) *recorder {
	return &recorder{ship: ship, mask: mask}
}

func (r *recorder) write(b []byte) {
	if r.cut {
		return
	}
	r.total += len(b)
	if r.total > maxTranscript {
		r.cut = true
		r.line()
		r.ship.Line(TranscriptStep, state.StreamStdout, "Recording truncated")
		return
	}
	r.partial = append(r.partial, b...)
	for {
		i := strings.IndexByte(string(r.partial), '\n')
		if i < 0 {
			break
		}
		line := strings.TrimRight(string(r.partial[:i]), "\r")
		r.partial = r.partial[i+1:]
		r.ship.Line(TranscriptStep, state.StreamStdout, r.mask.Mask(line))
	}
}

func (r *recorder) line() {
	if len(r.partial) > 0 {
		r.ship.Line(TranscriptStep, state.StreamStdout, r.mask.Mask(strings.TrimRight(string(r.partial), "\r")))
		r.partial = nil
	}
}

func (r *recorder) close() {
	r.line()
	r.ship.Close()
}

// maskerFor masks the workload's env values (8 bytes or longer) in transcripts.
func maskerFor(env map[string]secret.Secret) *build.Masker {
	vals := make([]string, 0, len(env))
	for _, v := range env {
		if len(v.Reveal()) >= 8 {
			vals = append(vals, v.Reveal())
		}
	}
	return build.NewMasker(vals...)
}
