package state

import (
	"strings"
	"testing"
	"time"
)

func TestRequestMetricValidation(t *testing.T) {
	at := time.Date(2026, 10, 19, 12, 1, 0, 0, time.UTC)
	ok := RequestMetricSample{Route: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9R1", At: at, Requests: 3, Status2xx: 2, Status5xx: 1, LatencyMs: make([]int64, LatencyBuckets)}
	report := func(rows ...RequestMetricSample) *Report {
		return &Report{TypeMeta: NewTypeMeta(KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: at, Seq: 1, Full: true,
			Metrics: &ReportMetrics{Host: []HostMetricSample{}, Workloads: []WorkloadMetricSample{}, Requests: rows}}
	}
	if err := report(ok).Validate(); err != nil {
		t.Fatalf("valid sample refused: %v", err)
	}
	mut := func(f func(*RequestMetricSample)) RequestMetricSample {
		s := ok
		s.LatencyMs = append([]int64(nil), ok.LatencyMs...)
		f(&s)
		return s
	}
	cases := map[string][]RequestMetricSample{
		"bad route":       {mut(func(s *RequestMetricSample) { s.Route = "dom_x" })},
		"not a minute":    {mut(func(s *RequestMetricSample) { s.At = at.Add(time.Second) })},
		"no requests":     {mut(func(s *RequestMetricSample) { s.Requests, s.Status2xx, s.Status5xx = 0, 0, 0 })},
		"status > total":  {mut(func(s *RequestMetricSample) { s.Status4xx = 5 })},
		"short histogram": {mut(func(s *RequestMetricSample) { s.LatencyMs = s.LatencyMs[:14] })},
		"hist > total":    {mut(func(s *RequestMetricSample) { s.LatencyMs[3] = 4 })},
		"negative bucket": {mut(func(s *RequestMetricSample) { s.LatencyMs[3] = -1 })},
		"negative bytes":  {mut(func(s *RequestMetricSample) { s.BytesOut = -1 })},
		"duplicate":       {ok, ok},
	}
	for name, rows := range cases {
		if err := report(rows...).Validate(); err == nil || !strings.Contains(err.Error(), "metrics.requests") {
			t.Errorf("%s: err = %v", name, err)
		}
	}
}
