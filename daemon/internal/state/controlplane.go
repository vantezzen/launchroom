package state

import (
	"fmt"
	"net"
	"net/netip"
	"net/url"
	"regexp"
	"strconv"
	"strings"
)

// ---- The dashboard's canonical origin (docs/03 §4.2, docs/05 §4.1, D43) ----

// ControlPlaneSpec is NodeState.system.controlPlane: the dashboard's
// canonical public origin. launchroomd moves its requests there once it has
// verified that the origin answers with responses signed by the pinned
// control-plane keys; its configured LR_CONTROL_PLANE_URL stays the fallback.
// Omitted when the dashboard has nothing to say (the control-plane host, or
// daemons without the controlPlaneUrl feature).
type ControlPlaneSpec struct {
	URL ControlPlaneOrigin `json:"url"`
}

var reControlPlaneOrigin = regexp.MustCompile(`^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+(?::[1-9][0-9]{0,4})?$`)

// maxControlPlaneOrigin bounds a ControlPlaneOrigin ("https://" + 253 + ":65535").
const maxControlPlaneOrigin = 267

// ControlPlaneOrigin is an https origin with a DNS name of at least two
// labels and an optional port: https://dash.example.com[:8443]. No IP
// literals, no path, no trailing slash.
type ControlPlaneOrigin string

// ProtocolSchema implements SchemaDescriber.
func (ControlPlaneOrigin) ProtocolSchema() ScalarSchema {
	return strSchema(reControlPlaneOrigin, maxControlPlaneOrigin)
}

// Validate checks the grammar, the host length, the port range and that the
// last label is not numeric (an IPv4 literal).
func (v ControlPlaneOrigin) Validate() error {
	if err := checkPattern("control-plane origin", string(v), reControlPlaneOrigin, maxControlPlaneOrigin); err != nil {
		return err
	}
	u, err := url.Parse(string(v))
	if err != nil {
		return fmt.Errorf("control-plane origin %q is invalid", truncate(string(v)))
	}
	host := u.Hostname()
	if len(host) > 253 {
		return fmt.Errorf("control-plane origin %q has a host longer than 253 bytes", truncate(string(v)))
	}
	if last := host[strings.LastIndexByte(host, '.')+1:]; strings.Trim(last, "0123456789") == "" {
		return fmt.Errorf("control-plane origin %q must name a DNS host, not an IP address", truncate(string(v)))
	}
	if p := u.Port(); p != "" {
		if n, err := strconv.Atoi(p); err != nil || n < 1 || n > 65535 {
			return fmt.Errorf("control-plane origin %q has an invalid port", truncate(string(v)))
		}
	}
	return nil
}

// URL parses the origin (call Validate first).
func (v ControlPlaneOrigin) URL() (*url.URL, error) { return url.Parse(string(v)) }

var reControlPlaneBaseURL = regexp.MustCompile(`^https?://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~%/-]*)?$`)

const maxControlPlaneBaseURL = 512

// ControlPlaneBaseURL is a dashboard base URL as launchroomd uses it
// (reported only): http(s)://<DNS name | IPv4 | [IPv6]>[:port][/path].
type ControlPlaneBaseURL string

// ProtocolSchema implements SchemaDescriber.
func (ControlPlaneBaseURL) ProtocolSchema() ScalarSchema {
	return strSchema(reControlPlaneBaseURL, maxControlPlaneBaseURL)
}

// Validate checks the grammar.
func (v ControlPlaneBaseURL) Validate() error {
	return checkPattern("control-plane URL", string(v), reControlPlaneBaseURL, maxControlPlaneBaseURL)
}

// NewControlPlaneBaseURL normalizes u for reporting (lowercase scheme and
// host, no credentials, query, fragment or trailing slash). It returns ""
// when the result does not match the grammar.
func NewControlPlaneBaseURL(u *url.URL) ControlPlaneBaseURL {
	if u == nil {
		return ""
	}
	host := strings.ToLower(u.Hostname())
	if strings.Contains(host, ":") {
		host = "[" + host + "]"
	}
	if p := u.Port(); p != "" {
		host += ":" + p
	}
	v := ControlPlaneBaseURL(strings.ToLower(u.Scheme) + "://" + host + strings.TrimRight(u.EscapedPath(), "/"))
	if v.Validate() != nil {
		return ""
	}
	return v
}

// OriginOf is the normalized origin of an http(s) URL: lowercase scheme and
// host, the port only when it isn't the scheme's default. "" when u has no
// http(s) scheme or host.
func OriginOf(u *url.URL) string {
	if u == nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" {
		return ""
	}
	scheme := strings.ToLower(u.Scheme)
	host := strings.ToLower(u.Hostname())
	if strings.Contains(host, ":") {
		host = "[" + host + "]"
	}
	if p := u.Port(); p != "" && (scheme != "https" || p != "443") && (scheme != "http" || p != "80") {
		host += ":" + p
	}
	return scheme + "://" + host
}

// IsLoopbackHost reports whether u names this host itself (localhost,
// 127.0.0.0/8, ::1): the control-plane host's own launchroomd, which never
// moves to a public origin.
func IsLoopbackHost(u *url.URL) bool {
	if u == nil {
		return false
	}
	h := strings.ToLower(strings.TrimSuffix(u.Hostname(), "."))
	if h == "localhost" || strings.HasSuffix(h, ".localhost") {
		return true
	}
	if a, err := netip.ParseAddr(h); err == nil {
		return a.Unmap().IsLoopback()
	}
	if ip := net.ParseIP(h); ip != nil {
		return ip.IsLoopback()
	}
	return false
}

// ControlPlaneURLState says which dashboard URL launchroomd talks to.
type ControlPlaneURLState string

// Control-plane URL states (Report.host.controlPlane.state).
const (
	// ControlPlaneConfigured: no canonical origin pushed, or it equals LR_CONTROL_PLANE_URL's.
	ControlPlaneConfigured ControlPlaneURLState = "configured"
	// ControlPlaneAdopted: requests go to the verified canonical origin.
	ControlPlaneAdopted ControlPlaneURLState = "adopted"
	// ControlPlaneFallback: adopted, but failing over to LR_CONTROL_PLANE_URL right now.
	ControlPlaneFallback ControlPlaneURLState = "fallback"
	// ControlPlanePending: the pushed origin is not verified yet (or verification fails); retrying.
	ControlPlanePending ControlPlaneURLState = "pending"
	// ControlPlaneRefused: the local policy refuses to follow (LR_CONTROL_PLANE_FOLLOW=off, loopback URL).
	ControlPlaneRefused ControlPlaneURLState = "refused"
)

var controlPlaneURLStates = []string{
	string(ControlPlaneConfigured), string(ControlPlaneAdopted), string(ControlPlaneFallback),
	string(ControlPlanePending), string(ControlPlaneRefused),
}

// ProtocolSchema implements SchemaDescriber.
func (ControlPlaneURLState) ProtocolSchema() ScalarSchema { return enumSchema(controlPlaneURLStates) }

// Validate checks the value.
func (v ControlPlaneURLState) Validate() error {
	return checkEnum("control-plane URL state", string(v), controlPlaneURLStates)
}

// maxControlPlaneMessage bounds ControlPlaneStatus.Message.
const maxControlPlaneMessage = 512

// ControlPlaneStatus is Report.host.controlPlane: which dashboard URL this
// launchroomd uses (docs/03 §4.2).
type ControlPlaneStatus struct {
	// Configured is LR_CONTROL_PLANE_URL, the permanent fallback.
	Configured ControlPlaneBaseURL `json:"configured,omitzero"`
	// Adopted is the verified system.controlPlane.url requests prefer.
	Adopted ControlPlaneOrigin `json:"adopted,omitzero"`
	// Active is the base URL of the last successful request.
	Active ControlPlaneBaseURL  `json:"active,omitzero"`
	State  ControlPlaneURLState `json:"state"`
	// Message is a short, secret-free reason for pending, refused or fallback.
	Message string `json:"message,omitzero" jsonschema:"maxLength=512"`
}

func (s *ControlPlaneStatus) validate(e *errs, p string) {
	if s.Configured != "" {
		e.check(p+".configured", s.Configured)
	}
	if s.Adopted != "" {
		e.check(p+".adopted", s.Adopted)
	}
	if s.Active != "" {
		e.check(p+".active", s.Active)
	}
	e.check(p+".state", s.State)
	checkText(e, p+".message", s.Message, maxControlPlaneMessage)
}
