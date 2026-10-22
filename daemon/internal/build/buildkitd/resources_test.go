package buildkitd

import (
	"context"
	"strings"
	"testing"
)

func TestPlanResources(t *testing.T) {
	for _, tc := range []struct {
		name string
		h    HostFacts
		o    Overrides
		want Resources // compared fields: MemoryMB, SwapMB, CPUs, Parallelism, Workers, NodeHeapMB, Tight, NoSwap
	}{
		{
			// The reported droplet: 1 vCPU, 2 GB, no swap, control plane and apps.
			// The old 75% (1.5 GB) on top of ~1.1 GB in use took the host down.
			name: "2 GB control plane, no swap",
			h:    HostFacts{CPUs: 1, MemoryMB: 1967, InUseMB: 1100, ControlPlane: true},
			want: Resources{MemoryMB: 512, CPUs: 0.75, Parallelism: 1, Workers: 1, NodeHeapMB: 384, Tight: true, NoSwap: true},
		},
		{
			name: "2 GB control plane with the installer's 2 GB swap",
			h:    HostFacts{CPUs: 1, MemoryMB: 1967, SwapMB: 2048, InUseMB: 1100, ControlPlane: true},
			want: Resources{MemoryMB: 512, SwapMB: 1024, CPUs: 0.75, Parallelism: 1, Workers: 1, NodeHeapMB: 1152, Tight: true},
		},
		{
			name: "4 GB control plane with two apps, measured below the floor",
			h:    HostFacts{CPUs: 2, MemoryMB: 3931, InUseMB: 900, ControlPlane: true, WorkloadsMB: []int{512, 128}},
			want: Resources{MemoryMB: 1792, CPUs: 1.75, Parallelism: 1, Workers: 1, NodeHeapMB: 1280, NoSwap: true},
		},
		{
			name: "4 GB app server, measured above the floor",
			h:    HostFacts{CPUs: 2, MemoryMB: 4096, SwapMB: 1024, InUseMB: 2500, WorkloadsMB: []int{1024, 1024}},
			want: Resources{MemoryMB: 1280, SwapMB: 768, CPUs: 1.75, Parallelism: 1, Workers: 1, NodeHeapMB: 1536},
		},
		{
			name: "8 GB dedicated build server",
			h:    HostFacts{CPUs: 4, MemoryMB: 8192, InUseMB: 400},
			want: Resources{MemoryMB: 6144, Parallelism: 4, Workers: 3, NodeHeapMB: 4608},
		},
		{
			name: "64 GB host keeps the 3/4 cap",
			h:    HostFacts{CPUs: 16, MemoryMB: 65536, InUseMB: 2000},
			want: Resources{MemoryMB: 49152, Parallelism: 16, Workers: 15, NodeHeapMB: 36864},
		},
		{
			name: "overrides win",
			h:    HostFacts{CPUs: 1, MemoryMB: 1967, InUseMB: 1100, ControlPlane: true},
			o:    Overrides{MemoryMB: 1024, CPUs: 1},
			want: Resources{MemoryMB: 1024, CPUs: 1, Parallelism: 1, Workers: 1, NodeHeapMB: 768, NoSwap: true},
		},
		{
			name: "unknown memory: no limit, Node default",
			h:    HostFacts{CPUs: 8},
			want: Resources{Parallelism: 8, Workers: 7},
		},
		{
			name: "tiny host below the build floor",
			h:    HostFacts{CPUs: 1, MemoryMB: 400},
			want: Resources{MemoryMB: 400, CPUs: 0.75, Parallelism: 1, Workers: 1, NodeHeapMB: 256, Tight: true, NoSwap: true},
		},
	} {
		t.Run(tc.name, func(t *testing.T) {
			got := PlanResources(tc.h, tc.o)
			if got.CPUShares != buildCPUShares || got.BlkioWeight != buildBlkioWeight || got.OOMScoreAdj != buildOOMScoreAdj {
				t.Fatalf("priority %+v", got)
			}
			if got.MemoryMB != tc.want.MemoryMB || got.SwapMB != tc.want.SwapMB || got.CPUs != tc.want.CPUs ||
				got.Parallelism != tc.want.Parallelism || got.Workers != tc.want.Workers || got.NodeHeapMB != tc.want.NodeHeapMB ||
				got.Tight != tc.want.Tight || got.NoSwap != tc.want.NoSwap {
				t.Fatalf("got  %+v\nwant %+v", got, tc.want)
			}
			// The host keeps its reserve: builds never get more than what is
			// left, except the 512 MB floor on hosts that are too small.
			if tc.o.MemoryMB == 0 && tc.h.MemoryMB > 0 && !got.Tight && got.MemoryMB > tc.h.MemoryMB-max(tc.h.InUseMB, osReserveMB(tc.h.MemoryMB)) {
				t.Fatalf("build memory %d leaves no reserve on %+v", got.MemoryMB, tc.h)
			}
		})
	}
}

func TestSettle(t *testing.T) {
	cur := PlanResources(HostFacts{CPUs: 8, MemoryMB: 16384, InUseMB: 4000}, Overrides{})
	// Noise (a few hundred MB more in use) keeps the container.
	noisy := PlanResources(HostFacts{CPUs: 8, MemoryMB: 16384, InUseMB: 4200}, Overrides{})
	if noisy.MemoryMB == cur.MemoryMB {
		t.Fatal("test needs a different plan")
	}
	if got := Settle(cur, noisy); got.MemoryMB != cur.MemoryMB {
		t.Fatalf("noise recreated: %d → %d", cur.MemoryMB, got.MemoryMB)
	}
	// A new app taking 1 GB changes it.
	grown := PlanResources(HostFacts{CPUs: 8, MemoryMB: 16384, InUseMB: 5200}, Overrides{})
	if got := Settle(cur, grown); got.MemoryMB != grown.MemoryMB {
		t.Fatalf("growth ignored: %d", got.MemoryMB)
	}
	// Facts that don't change the container follow at once.
	swapless := noisy
	swapless.NoSwap = true
	if got := Settle(cur, swapless); !got.NoSwap {
		t.Fatal("NoSwap not updated")
	}
	if got := Settle(Resources{}, noisy); got != noisy {
		t.Fatal("first plan not applied")
	}
}

func TestResourcesSummary(t *testing.T) {
	r := PlanResources(HostFacts{CPUs: 1, MemoryMB: 1967, SwapMB: 2048, InUseMB: 1100, ControlPlane: true}, Overrides{})
	if got := r.Summary(); got != "Builds are limited to 0.5 GB of memory (plus 1 GB of swap) and 0.75 CPU and run at low priority, so your apps and the dashboard stay responsive while they build." {
		t.Fatalf("%q", got)
	}
	if got := (Resources{CPUShares: 64}).Summary(); !strings.HasPrefix(got, "Builds run at low priority") {
		t.Fatalf("%q", got)
	}
	for mb, want := range map[int]string{512: "0.5 GB", 1024: "1 GB", 1792: "1.8 GB", 6144: "6 GB", 49152: "48 GB"} {
		if got := GB(mb); got != want {
			t.Errorf("GB(%d) = %q, want %q", mb, got, want)
		}
	}
	if len(SwapHint) > 400 {
		t.Fatalf("swap hint too long for a capability message: %d", len(SwapHint))
	}
}

// A running build keeps its container: a re-plan that would recreate it
// waits until the build server is idle.
func TestPlanResourcesWaitsForBuilds(t *testing.T) {
	inUse, busy := 1000, false
	m := New(Options{
		Measure: func(context.Context) HostFacts { return HostFacts{CPUs: 4, MemoryMB: 8192, InUseMB: inUse} },
		Busy:    func() bool { return busy },
	})
	m.planResources(t.Context())
	first := m.Resources()
	if first.MemoryMB == 0 || first.CPUShares != buildCPUShares {
		t.Fatalf("not planned: %+v", first)
	}
	h1 := m.SpecHash(image, appArmorUnconfined)
	inUse, busy = 3000, true
	m.planResources(t.Context())
	if m.Resources() != first || m.SpecHash(image, appArmorUnconfined) != h1 {
		t.Fatal("limits changed under a running build")
	}
	busy = false
	m.planResources(t.Context())
	if m.Resources().MemoryMB >= first.MemoryMB || m.SpecHash(image, appArmorUnconfined) == h1 {
		t.Fatalf("limits not re-planned when idle: %+v", m.Resources())
	}
	if !strings.Contains(string(m.Config()), "max-parallelism = 4") {
		t.Fatalf("config %s", m.Config())
	}
}
