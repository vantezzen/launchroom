package metrics

import (
	"bufio"
	"bytes"
	"context"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// skippedPrefixes are virtual interfaces: loopback, Docker bridges and veths,
// the mesh (tailscale0), tunnels and other container/VM plumbing. What is
// left is the traffic of the physical (or the VM's) interfaces.
var skippedPrefixes = []string{
	"lo", "docker", "br-", "veth", "tailscale", "cni", "flannel", "virbr", "lr-", "tunl", "sit", "ip6tnl", "ip6gre",
	"gre", "erspan", "ip_vti", "ip6_vti", "nlmon", "dummy", "kube", "cali", "vxlan", "genev", "ifb",
}

// SkipInterface reports whether name is left out of the network totals.
func SkipInterface(name string) bool {
	for _, p := range skippedPrefixes {
		if strings.HasPrefix(name, p) {
			return true
		}
	}
	return false
}

type hostReading struct {
	cpuPct, load1                          *float64
	buildCPU                               *float64
	memUsed, memTotal, diskUsed, diskTotal *float64
	rxBps, txBps                           *float64
}

type cpuTimes struct{ total, idle uint64 }

type netCounters struct{ rx, tx uint64 }

func ptr(v float64) *float64 { return &v }

func (c *Collector) read(rel string) ([]byte, bool) {
	f, err := os.Open(filepath.Join(c.o.ProcRoot, rel)) //nolint:gosec // fixed /proc paths
	if err != nil {
		return nil, false
	}
	defer func() { _ = f.Close() }()
	data, err := io.ReadAll(io.LimitReader(f, 1<<20))
	return data, err == nil
}

func (c *Collector) sampleHost(ctx context.Context, now time.Time) hostReading {
	var h hostReading
	if cur, ok := c.cpuTimes(); ok {
		if p := c.prevCPU; p != nil && cur.total > p.total && cur.idle >= p.idle {
			dt, di := float64(cur.total-p.total), float64(cur.idle-p.idle)
			h.cpuPct = ptr(min(100, max(0, (dt-di)/dt*100)))
		}
		c.prevCPU = &cur
	}
	if total, avail, ok := c.memory(); ok {
		h.memTotal, h.memUsed = ptr(float64(total)), ptr(float64(max(total-avail, 0)))
	}
	if c.o.Disk != nil {
		if fs, ok := c.o.Disk(ctx); ok && fs.TotalMB > 0 {
			h.diskTotal, h.diskUsed = ptr(float64(fs.TotalMB)), ptr(float64(max(fs.TotalMB-fs.FreeMB, 0)))
		}
	}
	if counters, ok := c.netCounters(); ok {
		if c.prevNet != nil {
			if secs := now.Sub(c.prevNetT).Seconds(); secs >= 1 {
				var rx, tx uint64
				for name, cur := range counters {
					prev, seen := c.prevNet[name]
					if !seen || cur.rx < prev.rx || cur.tx < prev.tx {
						continue // new interface or counter reset
					}
					rx += cur.rx - prev.rx
					tx += cur.tx - prev.tx
				}
				h.rxBps, h.txBps = ptr(float64(rx)/secs), ptr(float64(tx)/secs)
			}
		}
		c.prevNet, c.prevNetT = counters, now
	}
	h.load1 = c.load1()
	return h
}

// cpuTimes parses the aggregate "cpu" line of /proc/stat: total of user,
// nice, system, idle, iowait, irq, softirq, steal (guest time is included in
// user); idle = idle + iowait.
func (c *Collector) cpuTimes() (cpuTimes, bool) {
	data, ok := c.read("stat")
	if !ok {
		return cpuTimes{}, false
	}
	line, _, _ := bytes.Cut(data, []byte("\n"))
	f := strings.Fields(string(line))
	if len(f) < 5 || f[0] != "cpu" {
		return cpuTimes{}, false
	}
	var t cpuTimes
	for i, s := range f[1:min(len(f), 9)] {
		v, err := strconv.ParseUint(s, 10, 64)
		if err != nil {
			return cpuTimes{}, false
		}
		t.total += v
		if i == 3 || i == 4 {
			t.idle += v
		}
	}
	return t, true
}

// memory returns MemTotal and MemAvailable in MB.
func (c *Collector) memory() (total, avail int64, ok bool) {
	data, ok := c.read("meminfo")
	if !ok {
		return 0, 0, false
	}
	total, avail = -1, -1
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 2 {
			continue
		}
		v, err := strconv.ParseInt(f[1], 10, 64)
		if err != nil || v < 0 {
			continue
		}
		switch f[0] {
		case "MemTotal:":
			total = v >> 10
		case "MemAvailable:":
			avail = v >> 10
		}
	}
	if total < 0 || avail < 0 {
		return 0, 0, false
	}
	return total, min(avail, total), true
}

// netCounters parses /proc/net/dev (receive bytes is the 1st, transmit bytes
// the 9th number after the interface name).
func (c *Collector) netCounters() (map[string]netCounters, bool) {
	data, ok := c.read(filepath.Join("net", "dev"))
	if !ok {
		return nil, false
	}
	out := map[string]netCounters{}
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		name, rest, found := strings.Cut(sc.Text(), ":")
		if !found {
			continue
		}
		name = strings.TrimSpace(name)
		if name == "" || strings.Contains(name, "|") || c.o.SkipInterface(name) {
			continue
		}
		f := strings.Fields(rest)
		if len(f) < 9 {
			continue
		}
		rx, err1 := strconv.ParseUint(f[0], 10, 64)
		tx, err2 := strconv.ParseUint(f[8], 10, 64)
		if err1 != nil || err2 != nil {
			continue
		}
		out[name] = netCounters{rx: rx, tx: tx}
	}
	return out, true
}

func (c *Collector) load1() *float64 {
	data, ok := c.read("loadavg")
	if !ok {
		return nil
	}
	f := strings.Fields(string(data))
	if len(f) < 1 {
		return nil
	}
	v, err := strconv.ParseFloat(f[0], 64)
	if err != nil || v < 0 || v > 1e6 {
		return nil
	}
	return &v
}
