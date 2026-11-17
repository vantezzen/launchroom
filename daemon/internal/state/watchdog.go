package state

import (
	"fmt"
	"regexp"
	"slices"
	"strings"
	"time"
)

// ---- Control-plane watchdog and fallback notifications (docs/03 §5, docs/11) ----
//
// The control-plane host's launchroomd watches the dashboard's containers,
// restarts hung ones (never Postgres or Valkey) and, like one peer server,
// notifies a fallback channel when the dashboard stays unreachable. The
// dashboard turns it on per server (system.watchdog, daemon feature
// "watchdog"); the host owner can still turn it off locally
// (LR_WATCHDOG=off|notify). launchroomd reports what it saw and did in
// Report.controlPlane.

// WatchdogRole is what a server's watchdog does.
type WatchdogRole string

// Watchdog roles.
const (
	// WatchdogControlPlane: the control-plane host. Checks the dashboard's
	// readiness and containers, restarts hung ones, notifies.
	WatchdogControlPlane WatchdogRole = "control_plane"
	// WatchdogPeer: another server. Only notifies when it can't reach the
	// dashboard for a while.
	WatchdogPeer WatchdogRole = "peer"
)

var watchdogRoles = []string{string(WatchdogControlPlane), string(WatchdogPeer)}

// ProtocolSchema implements SchemaDescriber.
func (WatchdogRole) ProtocolSchema() ScalarSchema { return enumSchema(watchdogRoles) }

// Validate checks the value.
func (v WatchdogRole) Validate() error { return checkEnum("watchdog role", string(v), watchdogRoles) }

// WatchdogSpec is NodeState system.watchdog.
type WatchdogSpec struct {
	Role WatchdogRole `json:"role"`
	// Fallback is where launchroomd sends its notifications while the
	// dashboard can't; omitted: it only restarts (control_plane) or does
	// nothing (peer).
	Fallback *FallbackChannel `json:"fallback,omitzero" jsonschema:"nullable"`
}

// FallbackKind is the kind of a fallback channel.
type FallbackKind string

// Fallback channel kinds.
const (
	FallbackWebhook FallbackKind = "webhook"
	FallbackSlack   FallbackKind = "slack"
	FallbackDiscord FallbackKind = "discord"
	FallbackEmail   FallbackKind = "email"
)

var fallbackKinds = []string{string(FallbackWebhook), string(FallbackSlack), string(FallbackDiscord), string(FallbackEmail)}

// ProtocolSchema implements SchemaDescriber.
func (FallbackKind) ProtocolSchema() ScalarSchema { return enumSchema(fallbackKinds) }

// Validate checks the value.
func (v FallbackKind) Validate() error {
	return checkEnum("fallback channel kind", string(v), fallbackKinds)
}

// FallbackSecretKeys are the values the channel's sealed secret must hold
// (required) and may hold (optional), by kind.
func FallbackSecretKeys(k FallbackKind) (required, optional []string) {
	if k == FallbackEmail {
		return []string{"password"}, []string{"username"}
	}
	return []string{"url"}, nil
}

// Limits of fallback channels.
const (
	maxFallbackRecipients = 5
	maxServerLabel        = 128
)

// FallbackChannel is the alert channel launchroomd itself uses while the
// dashboard is down. Messages are constant templates naming the server and
// the dashboard's address, never errors or log text. Webhook, Slack and
// Discord URLs must be https; launchroomd refuses hosts that resolve to a
// private address unless AllowPrivateHosts.
type FallbackChannel struct {
	Kind FallbackKind `json:"kind"`
	// Secret is a sealed IngressSecret (purpose fallback_channel): "url"
	// (webhook, slack, discord; it carries the token) or "password" and an
	// optional "username" (email).
	Secret Sealed `json:"secret"`
	// SMTP is the mail server and addresses (kind email only).
	SMTP *FallbackSMTP `json:"smtp,omitzero" jsonschema:"nullable"`
	// AllowPrivateHosts was set explicitly when the channel was saved.
	AllowPrivateHosts bool `json:"allowPrivateHosts,omitzero"`
	// ServerLabel is how messages name this server (its display name, text only).
	ServerLabel string `json:"serverLabel" jsonschema:"minLength=1,maxLength=128"`
}

// FallbackSMTP is the mail server of an email fallback channel (implicit
// TLS on 465, STARTTLS required on every other port).
type FallbackSMTP struct {
	Host Hostname       `json:"host"`
	Port Port           `json:"port"`
	From EmailAddress   `json:"from"`
	To   []EmailAddress `json:"to" jsonschema:"minItems=1,maxItems=5"`
}

// EmailAddress is a plain address ("ops@example.com"): no display name, a
// lowercase DNS domain.
type EmailAddress string

var reEmail = regexp.MustCompile(`^[A-Za-z0-9._%+-]{1,64}@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)

// ProtocolSchema implements SchemaDescriber.
func (EmailAddress) ProtocolSchema() ScalarSchema { return strSchema(reEmail, 254) }

// Validate checks the grammar.
func (v EmailAddress) Validate() error { return checkPattern("email address", string(v), reEmail, 254) }

func (w *WatchdogSpec) validate(e *errs, p string) {
	e.check(p+".role", w.Role)
	f := w.Fallback
	if f == nil {
		return
	}
	fp := p + ".fallback"
	e.check(fp+".kind", f.Kind)
	e.check(fp+".secret", f.Secret)
	if f.ServerLabel == "" || len(f.ServerLabel) > maxServerLabel || hasControl(f.ServerLabel) {
		e.addf(fp+".serverLabel", "must be 1-%d bytes of text", maxServerLabel)
	}
	switch {
	case f.Kind == FallbackEmail && f.SMTP == nil:
		e.addf(fp+".smtp", "is required for email")
	case f.Kind != FallbackEmail && f.SMTP != nil:
		e.addf(fp+".smtp", "is only valid for email")
	}
	if s := f.SMTP; s != nil {
		e.check(fp+".smtp.host", s.Host)
		e.check(fp+".smtp.port", s.Port)
		e.check(fp+".smtp.from", s.From)
		checkCount(e, fp+".smtp.to", len(s.To), 1, maxFallbackRecipients)
		for i, to := range s.To {
			e.check(idx(fp+".smtp.to", i), to)
		}
		unique(e, fp+".smtp.to", s.To)
	}
}

// CheckFallbackSecret checks the opened values of a channel's secret
// against its kind; webhook, Slack and Discord URLs must be https URLs
// without credentials. Errors never contain values.
func CheckFallbackSecret(k FallbackKind, values map[string]string) error {
	required, optional := FallbackSecretKeys(k)
	for _, key := range required {
		if values[key] == "" {
			return fmt.Errorf("fallback channel: %s is required for %s", key, k)
		}
	}
	for key := range values {
		if !slices.Contains(required, key) && !slices.Contains(optional, key) {
			return fmt.Errorf("fallback channel: %q is not valid for %s", truncate(key), k)
		}
	}
	if u, ok := values["url"]; ok && (!strings.HasPrefix(u, "https://") || strings.ContainsAny(u, " @")) {
		return fmt.Errorf("fallback channel: the url must be https without credentials")
	}
	return nil
}

// ---- Report.controlPlane ----

// ControlPlaneService names a control-plane container the watchdog watches.
type ControlPlaneService string

// Control-plane services. The watchdog restarts only the first five.
const (
	CPDashboard ControlPlaneService = "dashboard"
	CPHorizon   ControlPlaneService = "horizon"
	CPReverb    ControlPlaneService = "reverb"
	CPScheduler ControlPlaneService = "scheduler"
	CPZot       ControlPlaneService = "zot"
	CPPostgres  ControlPlaneService = "postgres"
	CPValkey    ControlPlaneService = "valkey"
)

var controlPlaneServices = []string{
	string(CPDashboard), string(CPHorizon), string(CPReverb), string(CPScheduler), string(CPZot), string(CPPostgres), string(CPValkey),
}

// RestartableControlPlaneServices may be restarted by the watchdog (never
// the databases).
var RestartableControlPlaneServices = []ControlPlaneService{CPDashboard, CPHorizon, CPReverb, CPScheduler, CPZot}

// ProtocolSchema implements SchemaDescriber.
func (ControlPlaneService) ProtocolSchema() ScalarSchema { return enumSchema(controlPlaneServices) }

// Validate checks the value.
func (v ControlPlaneService) Validate() error {
	return checkEnum("control-plane service", string(v), controlPlaneServices)
}

// ServiceState is how a control-plane container is doing.
type ServiceState string

// Control-plane service states.
const (
	ServiceRunning    ServiceState = "running"
	ServiceUnhealthy  ServiceState = "unhealthy" // running, its healthcheck fails
	ServiceRestarting ServiceState = "restarting"
	ServiceStopped    ServiceState = "stopped"
	ServiceMissing    ServiceState = "missing" // no container
)

var serviceStates = []string{string(ServiceRunning), string(ServiceUnhealthy), string(ServiceRestarting), string(ServiceStopped), string(ServiceMissing)}

// ProtocolSchema implements SchemaDescriber.
func (ServiceState) ProtocolSchema() ScalarSchema { return enumSchema(serviceStates) }

// Validate checks the value.
func (v ServiceState) Validate() error { return checkEnum("service state", string(v), serviceStates) }

// WatchdogRestartReason is why the watchdog restarted a service.
type WatchdogRestartReason string

// Watchdog restart reasons.
const (
	RestartUnhealthy WatchdogRestartReason = "unhealthy" // its healthcheck failed 3 times
	RestartNotReady  WatchdogRestartReason = "not_ready" // the dashboard's readiness check failed 3 times
	RestartExited    WatchdogRestartReason = "exited"    // it stopped and didn't come back
)

var watchdogRestartReasons = []string{string(RestartUnhealthy), string(RestartNotReady), string(RestartExited)}

// ProtocolSchema implements SchemaDescriber.
func (WatchdogRestartReason) ProtocolSchema() ScalarSchema { return enumSchema(watchdogRestartReasons) }

// Validate checks the value.
func (v WatchdogRestartReason) Validate() error {
	return checkEnum("watchdog restart reason", string(v), watchdogRestartReasons)
}

// FallbackNoticeKind is which fallback message was sent.
type FallbackNoticeKind string

// Fallback notices.
const (
	NoticeDown      FallbackNoticeKind = "down"       // the dashboard can't be reached
	NoticeStillDown FallbackNoticeKind = "still_down" // repeated every 6 hours
	NoticeBack      FallbackNoticeKind = "back"       // it is reachable again
)

var fallbackNoticeKinds = []string{string(NoticeDown), string(NoticeStillDown), string(NoticeBack)}

// ProtocolSchema implements SchemaDescriber.
func (FallbackNoticeKind) ProtocolSchema() ScalarSchema { return enumSchema(fallbackNoticeKinds) }

// Validate checks the value.
func (v FallbackNoticeKind) Validate() error {
	return checkEnum("fallback notice kind", string(v), fallbackNoticeKinds)
}

// Limits of Report.controlPlane.
const (
	maxWatchdogRestarts = 64
	maxFallbackNotices  = 32
)

// ControlPlaneHealth is Report.controlPlane: what the watchdog saw and did
// (control-plane host; notices also on the peer). Restarts and notices are
// repeated until a report carrying them was accepted.
type ControlPlaneHealth struct {
	CheckedAt time.Time `json:"checkedAt"`
	// Ready is the dashboard's last readiness check (database and cache); omitted on a peer.
	Ready *bool `json:"ready,omitzero" jsonschema:"nullable"`
	// Services are the control-plane containers (control-plane host only).
	Services []ControlPlaneServiceStatus `json:"services,omitzero" jsonschema:"nullable,maxItems=16"`
	// Restarts are the watchdog's restarts not delivered yet.
	Restarts []WatchdogRestart `json:"restarts,omitzero" jsonschema:"nullable,maxItems=64"`
	// Notices are fallback messages sent (or failed) and not delivered yet.
	Notices []FallbackNotice `json:"notices,omitzero" jsonschema:"nullable,maxItems=32"`
}

// ControlPlaneServiceStatus is one control-plane container.
type ControlPlaneServiceStatus struct {
	Name      ControlPlaneService `json:"name"`
	State     ServiceState        `json:"state"`
	StartedAt *time.Time          `json:"startedAt,omitzero" jsonschema:"nullable"`
	// Restarts is Docker's restart count of the container.
	Restarts  int  `json:"restarts" jsonschema:"minimum=0"`
	OOMKilled bool `json:"oomKilled,omitzero"`
}

// WatchdogRestart is one restart the watchdog did.
type WatchdogRestart struct {
	Service ControlPlaneService   `json:"service"`
	At      time.Time             `json:"at"`
	Reason  WatchdogRestartReason `json:"reason"`
}

// FallbackNotice is one fallback message.
type FallbackNotice struct {
	Kind FallbackNoticeKind `json:"kind"`
	At   time.Time          `json:"at"`
	Sent bool               `json:"sent"`
	// Error is why sending failed (secret-free: never the URL).
	Error string `json:"error,omitzero" jsonschema:"maxLength=512"`
}

func (c *ControlPlaneHealth) validate(e *errs, p string) {
	if c.CheckedAt.IsZero() {
		e.addf(p+".checkedAt", "is required")
	}
	checkCount(e, p+".services", len(c.Services), 0, len(controlPlaneServices))
	names := make([]ControlPlaneService, 0, len(c.Services))
	for i, s := range c.Services {
		sp := idx(p+".services", i)
		e.check(sp+".name", s.Name)
		e.check(sp+".state", s.State)
		if s.Restarts < 0 {
			e.addf(sp+".restarts", "must be >= 0")
		}
		names = append(names, s.Name)
	}
	unique(e, p+".services.name", names)
	checkCount(e, p+".restarts", len(c.Restarts), 0, maxWatchdogRestarts)
	for i, r := range c.Restarts {
		rp := idx(p+".restarts", i)
		e.check(rp+".service", r.Service)
		if !slices.Contains(RestartableControlPlaneServices, r.Service) {
			e.addf(rp+".service", "is never restarted by the watchdog")
		}
		if r.At.IsZero() {
			e.addf(rp+".at", "is required")
		}
		e.check(rp+".reason", r.Reason)
	}
	checkCount(e, p+".notices", len(c.Notices), 0, maxFallbackNotices)
	for i, n := range c.Notices {
		np := idx(p+".notices", i)
		e.check(np+".kind", n.Kind)
		if n.At.IsZero() {
			e.addf(np+".at", "is required")
		}
		checkText(e, np+".error", n.Error, 512)
	}
}
