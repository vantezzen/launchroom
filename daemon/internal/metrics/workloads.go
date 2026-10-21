package metrics

import (
	"context"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// ctrReading is the previous CPU counter of a container.
type ctrReading struct {
	read  time.Time
	cpuNs uint64
}

type workloadReading struct {
	id           state.WorkloadID
	cores, memMB *float64
}

type workloadReadings struct {
	at    time.Time
	items []workloadReading
}

// sampleWorkloads reads the stats of every running managed workload
// container (bounded concurrency, per-call timeout). A container whose stats
// fail is left out of this sample.
func (c *Collector) sampleWorkloads(ctx context.Context, now time.Time) workloadReadings {
	out := workloadReadings{at: now}
	if c.o.Containers == nil || c.o.Stats == nil {
		return out
	}
	lctx, cancel := context.WithTimeout(ctx, statsTimeout)
	ctrs, err := c.o.Containers.ListContainers(lctx, nil)
	cancel()
	if err != nil {
		c.o.Log.Debug("listing containers for metrics failed", "err", err)
		return out
	}
	type job struct {
		id string
		wl state.WorkloadID
	}
	var jobs []job
	for _, ct := range ctrs {
		wl := state.WorkloadID(ct.Labels[docker.LabelWorkload])
		if !ct.Running || wl == "" || wl.Validate() != nil {
			continue
		}
		jobs = append(jobs, job{ct.ID, wl})
	}
	results := make([]*docker.ContainerStats, len(jobs))
	var wg sync.WaitGroup
	sem := make(chan struct{}, statsWorkers)
	for i, j := range jobs {
		wg.Add(1)
		go func() {
			defer wg.Done()
			select {
			case sem <- struct{}{}:
			case <-ctx.Done():
				return
			}
			defer func() { <-sem }()
			sctx, cancel := context.WithTimeout(ctx, statsTimeout)
			defer cancel()
			s, err := c.o.Stats.ContainerStats(sctx, j.id)
			if err != nil {
				c.o.Log.Debug("container stats failed", "workload", j.wl, "err", err)
				return
			}
			results[i] = &s
		}()
	}
	wg.Wait()

	seen := make(map[string]bool, len(jobs))
	for i, j := range jobs {
		s := results[i]
		if s == nil {
			continue
		}
		seen[j.id] = true
		r := workloadReading{id: j.wl}
		read := s.Read
		if read.IsZero() {
			read = now
		}
		if prev, ok := c.prevCtr[j.id]; ok && read.After(prev.read) && s.CPUTotalNs >= prev.cpuNs {
			cores := float64(s.CPUTotalNs-prev.cpuNs) / float64(read.Sub(prev.read).Nanoseconds())
			r.cores = ptr(min(max(cores, 0), 4096))
		}
		c.prevCtr[j.id] = ctrReading{read: read, cpuNs: s.CPUTotalNs}
		if s.HasMemory {
			r.memMB = ptr(float64(s.MemUsedBytes) / (1 << 20))
		}
		if r.cores != nil || r.memMB != nil {
			out.items = append(out.items, r)
		}
	}
	for id := range c.prevCtr {
		if !seen[id] {
			delete(c.prevCtr, id)
		}
	}
	return out
}
