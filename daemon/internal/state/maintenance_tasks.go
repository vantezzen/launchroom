package state

// ---- cleanup and cert_retry: typed fixes from the server page ----

// CleanupSpec asks launchroomd to free disk space now, with the settings of
// its disk-pressure cleanup (unused launchroom images beyond retention,
// BuildKit cache, leftover one-off containers; never volumes). The result
// is TaskCompletion.cleanup with trigger "manual" (daemon feature
// "cleanupTask"). It has no parameters.
type CleanupSpec struct{}

// TaskType implements TaskSpec.
func (*CleanupSpec) TaskType() TaskType { return TaskCleanup }

// Validate implements TaskSpec.
func (*CleanupSpec) Validate() error { return nil }

// MaxCertRetryHosts bounds CertRetrySpec.hosts.
const MaxCertRetryHosts = 100

// CertRetrySpec asks the edge to try obtaining certificates for these hosts
// again now, instead of waiting for Caddy's backoff (daemon feature
// "certificateRetry"). Every host must be a host of a route in the edge's
// verified NodeState; a host still within its CA's retryAfter is skipped.
// The task succeeds once the attempt was started; the outcome shows in
// Report.ingress.certificates.
type CertRetrySpec struct {
	Hosts []Hostname `json:"hosts" jsonschema:"minItems=1,maxItems=100"`
}

// TaskType implements TaskSpec.
func (*CertRetrySpec) TaskType() TaskType { return TaskCertRetry }

// Validate implements TaskSpec.
func (s *CertRetrySpec) Validate() error {
	var e errs
	checkCount(&e, "hosts", len(s.Hosts), 1, MaxCertRetryHosts)
	for i, h := range s.Hosts {
		e.check(idx("hosts", i), h)
	}
	unique(&e, "hosts", s.Hosts)
	return e.err()
}
