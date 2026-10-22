package state

import (
	"math"
	"time"
)

// Usage metrics (docs/design/direction.md §8.2). launchroomd samples the host
// and its managed containers every 15 s, aggregates per minute and sends the
// completed minutes it has not delivered yet in every report (bounded buffer
// of about an hour). The dashboard stores them idempotently per
// (server, minute) and (workload, minute), so a minute sent twice is harmless.

// Bounds of Report.metrics.
const (
	MaxHostMetricSamples     = 120
	MaxWorkloadMetricSamples = 8192
	MaxRequestMetricSamples  = 8192
	// LatencyBuckets is the number of latency histogram buckets: one per
	// bound in LatencyBoundsMs plus an overflow bucket.
	LatencyBuckets = 15
)

// LatencyBoundsMs are the inclusive upper bounds (milliseconds) of the
// request latency histogram buckets (docs/15 §1.1). Bucket i counts requests
// with duration ≤ LatencyBoundsMs[i] (and > the previous bound); the last
// bucket counts everything slower than 30 s. Fixed so histograms add up
// exactly across servers, minutes and rollups.
var LatencyBoundsMs = [LatencyBuckets - 1]float64{1, 2, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000}

// ReportMetrics carries per-minute usage aggregates. Omitted when there is nothing new.
type ReportMetrics struct {
	// Host are per-minute host aggregates, oldest first.
	Host []HostMetricSample `json:"host" jsonschema:"maxItems=120"`
	// Workloads are per-minute aggregates of the managed workload containers, oldest first.
	Workloads []WorkloadMetricSample `json:"workloads" jsonschema:"maxItems=8192"`
	// Requests are per-minute traffic aggregates of the routes this edge
	// serves, from Caddy's access log (docs/15 §1); omitted when there are none.
	Requests []RequestMetricSample `json:"requests,omitempty" jsonschema:"maxItems=8192"`
}

// RequestMetricSample is one minute of one route's traffic at this edge.
// Counts are of access log lines that arrived in that minute; 1xx responses
// (e.g. WebSocket upgrades, whose "duration" is the connection's lifetime)
// count only in Requests.
type RequestMetricSample struct {
	// Route is the NodeState route the request's host belongs to.
	Route RouteID `json:"route"`
	// At is the start of the minute (UTC, whole minute).
	At time.Time `json:"at"`
	// Requests is the number of requests (all status codes).
	Requests  int64 `json:"requests" jsonschema:"minimum=1"`
	Status2xx int64 `json:"status2xx,omitzero" jsonschema:"minimum=0"`
	Status3xx int64 `json:"status3xx,omitzero" jsonschema:"minimum=0"`
	Status4xx int64 `json:"status4xx,omitzero" jsonschema:"minimum=0"`
	Status5xx int64 `json:"status5xx,omitzero" jsonschema:"minimum=0"`
	// BytesIn are request body bytes read; BytesOut response bytes written.
	BytesIn  int64 `json:"bytesIn,omitzero" jsonschema:"minimum=0"`
	BytesOut int64 `json:"bytesOut,omitzero" jsonschema:"minimum=0"`
	// LatencyMs counts non-1xx requests per duration bucket (LatencyBoundsMs
	// plus overflow, 15 entries); omitted when there was no such request.
	LatencyMs []int64 `json:"latencyMs,omitempty" jsonschema:"minItems=15,maxItems=15"`
}

// LatencyBucket returns the histogram bucket of a duration in milliseconds.
func LatencyBucket(ms float64) int {
	for i, b := range LatencyBoundsMs {
		if ms <= b {
			return i
		}
	}
	return LatencyBuckets - 1
}

// HostMetricSample is one minute of host usage (averages over the samples of
// that minute). A metric that could not be read is omitted.
type HostMetricSample struct {
	// At is the start of the minute (UTC, whole minute).
	At time.Time `json:"at"`
	// Samples is the number of 15 s samples aggregated.
	Samples int `json:"samples" jsonschema:"minimum=1,maximum=60"`
	// CPUPct is busy time of all CPUs from /proc/stat, 0–100.
	CPUPct *float64 `json:"cpuPct,omitzero" jsonschema:"nullable,minimum=0,maximum=100"`
	// BuildCPUCores is the managed BuildKit container's CPU, including builds.
	BuildCPUCores *float64 `json:"buildCpuCores,omitzero" jsonschema:"nullable,minimum=0,maximum=4096"`
	// MemUsedMB is MemTotal − MemAvailable from /proc/meminfo.
	MemUsedMB  *int64 `json:"memUsedMb,omitzero" jsonschema:"nullable,minimum=0"`
	MemTotalMB *int64 `json:"memTotalMb,omitzero" jsonschema:"nullable,minimum=0"`
	// DiskUsedMB and DiskTotalMB describe Docker's filesystem (else the data dir's).
	DiskUsedMB  *int64 `json:"diskUsedMb,omitzero" jsonschema:"nullable,minimum=0"`
	DiskTotalMB *int64 `json:"diskTotalMb,omitzero" jsonschema:"nullable,minimum=0"`
	// NetRxBps and NetTxBps are bytes per second over the physical interfaces
	// (/proc/net/dev without lo, Docker bridges, veths and the mesh interface).
	NetRxBps *int64 `json:"netRxBps,omitzero" jsonschema:"nullable,minimum=0"`
	NetTxBps *int64 `json:"netTxBps,omitzero" jsonschema:"nullable,minimum=0"`
	// Load1 is the 1-minute load average.
	Load1 *float64 `json:"load1,omitzero" jsonschema:"nullable,minimum=0,maximum=1000000"`
}

// WorkloadMetricSample is one minute of one workload container's usage.
type WorkloadMetricSample struct {
	Workload WorkloadID `json:"workload"`
	// At is the start of the minute (UTC, whole minute).
	At time.Time `json:"at"`
	// CPUCores is the average CPU used, in cores (0.5 = half a core).
	CPUCores *float64 `json:"cpuCores,omitzero" jsonschema:"nullable,minimum=0,maximum=4096"`
	// MemMB is the average memory used (Docker's usage minus inactive file cache).
	MemMB *int64 `json:"memMb,omitzero" jsonschema:"nullable,minimum=0"`
}

func checkMinute(e *errs, p string, at time.Time) {
	if at.IsZero() {
		e.addf(p, "is required")
		return
	}
	if !at.Equal(at.Truncate(time.Minute)) {
		e.addf(p, "must be a whole minute")
	}
}

func checkFloat(e *errs, p string, v *float64, maxV float64) {
	if v != nil && (math.IsNaN(*v) || *v < 0 || *v > maxV) {
		e.addf(p, "must be between 0 and %g", maxV)
	}
}

func checkNonNegative(e *errs, p string, vs map[string]*int64) {
	for k, v := range vs {
		if v != nil && *v < 0 {
			e.addf(p+"."+k, "must be >= 0")
		}
	}
}

func (m *ReportMetrics) validate(e *errs, p string) {
	checkCount(e, p+".host", len(m.Host), 0, MaxHostMetricSamples)
	checkCount(e, p+".workloads", len(m.Workloads), 0, MaxWorkloadMetricSamples)
	minutes := make([]int64, 0, len(m.Host))
	for i, s := range m.Host {
		q := idx(p+".host", i)
		checkMinute(e, q+".at", s.At)
		minutes = append(minutes, s.At.Unix())
		if s.Samples < 1 || s.Samples > 60 {
			e.addf(q+".samples", "must be between 1 and 60")
		}
		checkFloat(e, q+".cpuPct", s.CPUPct, 100)
		checkFloat(e, q+".buildCpuCores", s.BuildCPUCores, 4096)
		checkFloat(e, q+".load1", s.Load1, 1e6)
		checkNonNegative(e, q, map[string]*int64{
			"memUsedMb": s.MemUsedMB, "memTotalMb": s.MemTotalMB, "diskUsedMb": s.DiskUsedMB,
			"diskTotalMb": s.DiskTotalMB, "netRxBps": s.NetRxBps, "netTxBps": s.NetTxBps,
		})
	}
	unique(e, p+".host[].at", minutes)
	type key struct {
		w  WorkloadID
		at int64
	}
	keys := make([]key, 0, len(m.Workloads))
	for i, s := range m.Workloads {
		q := idx(p+".workloads", i)
		e.check(q+".workload", s.Workload)
		checkMinute(e, q+".at", s.At)
		checkFloat(e, q+".cpuCores", s.CPUCores, 4096)
		checkNonNegative(e, q, map[string]*int64{"memMb": s.MemMB})
		keys = append(keys, key{s.Workload, s.At.Unix()})
	}
	unique(e, p+".workloads[].(workload,at)", keys)
	checkCount(e, p+".requests", len(m.Requests), 0, MaxRequestMetricSamples)
	type rkey struct {
		r  RouteID
		at int64
	}
	rkeys := make([]rkey, 0, len(m.Requests))
	for i, s := range m.Requests {
		q := idx(p+".requests", i)
		e.check(q+".route", s.Route)
		checkMinute(e, q+".at", s.At)
		rkeys = append(rkeys, rkey{s.Route, s.At.Unix()})
		if s.Requests < 1 {
			e.addf(q+".requests", "must be >= 1")
		}
		checkNonNegative(e, q, map[string]*int64{
			"status2xx": &s.Status2xx, "status3xx": &s.Status3xx, "status4xx": &s.Status4xx, "status5xx": &s.Status5xx,
			"bytesIn": &s.BytesIn, "bytesOut": &s.BytesOut,
		})
		if s.Status2xx+s.Status3xx+s.Status4xx+s.Status5xx > s.Requests {
			e.addf(q, "status counts exceed requests")
		}
		if s.LatencyMs == nil {
			continue
		}
		if len(s.LatencyMs) != LatencyBuckets {
			e.addf(q+".latencyMs", "must have exactly %d entries", LatencyBuckets)
			continue
		}
		var sum int64
		for j, n := range s.LatencyMs {
			if n < 0 {
				e.addf(idx(q+".latencyMs", j), "must be >= 0")
			}
			sum += n
		}
		if sum > s.Requests {
			e.addf(q+".latencyMs", "counts exceed requests")
		}
	}
	unique(e, p+".requests[].(route,at)", rkeys)
}
