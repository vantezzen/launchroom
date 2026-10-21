package hostinfo

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// HardwareOptions configures Hardware. Every root defaults to the real one;
// tests point them at fake trees.
type HardwareOptions struct {
	// ProcRoot is /proc. With `pid: host` (production), /proc/1 is the host's init.
	ProcRoot string
	// SysRoot is /sys.
	SysRoot string
	// RootDir is / (for /.dockerenv and the daemon's own /etc).
	RootDir string
	// Engine returns Docker's view of the host (OS name, kernel); nil = skipped.
	Engine func(ctx context.Context) (docker.EngineInfo, error)
	// Timezone is the configured host zone (LR_HOST_TIMEZONE, written by the installer); wins when valid.
	Timezone string
	// Kernel returns the kernel release (uname); nil = skipped.
	Kernel func() string
	// Pid is launchroomd's own pid (default os.Getpid()).
	Pid int
	// HostModel is the machine model the installer read on the host
	// (LR_HOST_MODEL; macOS hides it from the VM, D53).
	HostModel string
}

// hostInits are process names of a real host's PID 1. When launchroomd sees
// another PID 1 while running in a container (/.dockerenv), it shares the PID
// namespace of a container, not the host's: the "server" is itself a
// container (the dind dev servers, LXC-like setups). Production runs with
// `pid: host`, so PID 1 is the host's init there.
var hostInits = map[string]bool{
	"systemd": true, "init": true, "openrc-init": true, "runit": true, "s6-svscan": true, "launchd": true, "upstart": true,
}

// armImplementers maps /proc/cpuinfo "CPU implementer" to a vendor.
var armImplementers = map[string]string{
	"0x41": "ARM", "0x42": "Broadcom", "0x43": "Cavium", "0x48": "HiSilicon", "0x4e": "NVIDIA",
	"0x50": "APM", "0x51": "Qualcomm", "0x61": "Apple", "0x6d": "Microsoft", "0xc0": "Ampere",
}

// armParts maps ARM Ltd. "CPU part" numbers to core names.
var armParts = map[string]string{
	"0xd03": "Cortex-A53", "0xd04": "Cortex-A35", "0xd05": "Cortex-A55", "0xd07": "Cortex-A57", "0xd08": "Cortex-A72",
	"0xd09": "Cortex-A73", "0xd0a": "Cortex-A75", "0xd0b": "Cortex-A76", "0xd0c": "Neoverse-N1", "0xd0d": "Cortex-A77",
	"0xd40": "Neoverse-V1", "0xd41": "Cortex-A78", "0xd44": "Cortex-X1", "0xd46": "Cortex-A510", "0xd47": "Cortex-A710",
	"0xd49": "Neoverse-N2", "0xd4d": "Cortex-A715", "0xd4f": "Neoverse-V2", "0xd80": "Cortex-A520", "0xd81": "Cortex-A720",
}

// Hardware reads the machine's facts from local files (docs/design/direction.md
// §7.3): no network, no shell, never serials or UUIDs. What cannot be read is
// omitted; the result is nil only when nothing at all was found.
func Hardware(ctx context.Context, o HardwareOptions) *state.HardwareFacts {
	if o.ProcRoot == "" {
		o.ProcRoot = "/proc"
	}
	if o.SysRoot == "" {
		o.SysRoot = "/sys"
	}
	if o.RootDir == "" {
		o.RootDir = "/"
	}
	if o.Pid == 0 {
		o.Pid = os.Getpid()
	}
	h := &state.HardwareFacts{}
	cpu := readCPUInfo(o.ProcRoot)
	h.CPUModel, h.CPUVendor = cpu.model, cpu.vendor
	h.DMI = readDMI(o.SysRoot)
	h.DeviceTreeModel = firstText(
		readText(filepath.Join(o.ProcRoot, "device-tree", "model")),
		readText(filepath.Join(o.SysRoot, "firmware", "devicetree", "base", "model")),
		cpu.boardModel,
	)
	var engine docker.EngineInfo
	if o.Engine != nil {
		ectx, cancel := context.WithTimeout(ctx, 5*time.Second)
		engine, _ = o.Engine(ectx)
		cancel()
	}
	h.OSName = FactText(engine.OperatingSystem)
	h.Kernel = FactText(engine.KernelVersion)
	if h.Kernel == "" && o.Kernel != nil {
		h.Kernel = FactText(o.Kernel())
	}
	h.Virtualization = virtualization(o, h.DMI, cpu.hypervisor, engine)
	h.Timezone = hostTimezone(o)
	h.Battery = hasSystemBattery(o.SysRoot)
	h.HostModel = FactText(o.HostModel)
	if *h == (state.HardwareFacts{}) {
		return nil
	}
	if h.Validate() != nil {
		// Defensive: sanitization makes this unreachable.
		return nil
	}
	return h
}

type cpuInfo struct {
	model, vendor, boardModel state.FactText
	hypervisor                bool
}

func readCPUInfo(procRoot string) cpuInfo {
	data, err := readLimited(filepath.Join(procRoot, "cpuinfo"))
	if err != nil {
		return cpuInfo{}
	}
	var out cpuInfo
	var implementer, part, hardware string
	sc := bufio.NewScanner(bytes.NewReader(data))
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		key, val, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		key, val = strings.TrimSpace(key), strings.TrimSpace(val)
		switch key {
		case "model name":
			if out.model == "" {
				out.model = FactText(val)
			}
		case "vendor_id":
			if out.vendor == "" {
				out.vendor = FactText(val)
			}
		case "CPU implementer":
			if implementer == "" {
				implementer = strings.ToLower(val)
			}
		case "CPU part":
			if part == "" {
				part = strings.ToLower(val)
			}
		case "Hardware":
			hardware = val
		case "Model":
			// Raspberry Pi kernels print the board model here.
			out.boardModel = FactText(val)
		case "flags":
			if !out.hypervisor {
				out.hypervisor = slices.Contains(strings.Fields(val), "hypervisor")
			}
		}
	}
	if out.vendor == "" && implementer != "" {
		out.vendor = FactText(armImplementers[implementer])
	}
	if out.model == "" {
		if name := armParts[part]; implementer == "0x41" && name != "" {
			out.model = FactText(name)
		} else if hardware != "" {
			out.model = FactText(hardware)
		}
	}
	return out
}

// readDMI reads the world-readable SMBIOS fields. Serial numbers and UUIDs
// (product_serial, product_uuid, board_serial, chassis_serial) are root-only
// and are never read.
func readDMI(sysRoot string) *state.DMIFacts {
	dir := filepath.Join(sysRoot, "class", "dmi", "id")
	read := func(name string) state.FactText { return readText(filepath.Join(dir, name)) }
	d := &state.DMIFacts{
		SysVendor: read("sys_vendor"), ProductName: read("product_name"), ProductFamily: read("product_family"),
		BoardVendor: read("board_vendor"), BoardName: read("board_name"), BIOSVendor: read("bios_vendor"),
	}
	if n, err := strconv.Atoi(string(read("chassis_type"))); err == nil && n > 0 && n <= 255 {
		d.ChassisType = n
	}
	if *d == (state.DMIFacts{}) {
		return nil
	}
	return d
}

// hasSystemBattery: any /sys/class/power_supply entry of type "Battery"
// whose scope is not "Device" (peripherals such as a wireless mouse report
// scope Device; system batteries report System or no scope).
func hasSystemBattery(sysRoot string) bool {
	dir := filepath.Join(sysRoot, "class", "power_supply")
	entries, err := os.ReadDir(dir)
	if err != nil {
		return false
	}
	for _, e := range entries[:min(len(entries), 64)] {
		supply := filepath.Join(dir, e.Name())
		if !strings.EqualFold(string(readText(filepath.Join(supply, "type"))), "battery") {
			continue
		}
		if strings.EqualFold(string(readText(filepath.Join(supply, "scope"))), "device") {
			continue
		}
		return true
	}
	return false
}

// virtualization names the hypervisor: DMI strings first (they name it),
// then Xen's /sys/hypervisor, the container heuristic (see hostInits),
// Docker Desktop / OrbStack, and finally the CPU "hypervisor" flag.
func virtualization(o HardwareOptions, d *state.DMIFacts, cpuFlag bool, engine docker.EngineInfo) state.Virtualization {
	if v := dmiVirtualization(d); v != "" {
		return v
	}
	if t := readText(filepath.Join(o.SysRoot, "hypervisor", "type")); strings.EqualFold(string(t), "xen") {
		return state.VirtXen
	}
	osName, kernel := strings.ToLower(engine.OperatingSystem), strings.ToLower(engine.KernelVersion)
	// The engine's own name first: with `pid: host` OrbStack's PID 1 is its
	// "simplevisor", which the container heuristic would take for a
	// container (D53). A dind container reports its own OS, never these.
	switch {
	case osName == "docker desktop" && (strings.Contains(kernel, "microsoft") || strings.Contains(kernel, "wsl")):
		return state.VirtHyperV
	case osName == "docker desktop", osName == "orbstack":
		// Docker Desktop and OrbStack on a Mac run Linux in a VM (Apple Virtualization).
		return state.VirtApple
	}
	if inContainer(o) {
		return state.VirtContainer
	}
	switch {
	case strings.Contains(kernel, "orbstack"):
		return state.VirtApple
	case cpuFlag:
		return state.VirtOther
	}
	return ""
}

func dmiVirtualization(d *state.DMIFacts) state.Virtualization {
	if d == nil {
		return ""
	}
	all := strings.ToLower(strings.Join([]string{string(d.SysVendor), string(d.ProductName), string(d.BoardVendor), string(d.BIOSVendor), string(d.ProductFamily)}, "|"))
	vendor, product := strings.ToLower(string(d.SysVendor)), strings.ToLower(string(d.ProductName))
	switch {
	case strings.Contains(all, "vmware"):
		return state.VirtVMware
	case strings.Contains(all, "virtualbox"), strings.Contains(all, "innotek"):
		return state.VirtVirtualBox
	case strings.Contains(all, "parallels"):
		return state.VirtParallels
	case strings.Contains(product, "apple virtualization"), strings.Contains(all, "apple virtualization"):
		return state.VirtApple
	case strings.Contains(all, "bhyve"):
		return state.VirtBhyve
	case vendor == "microsoft corporation" && strings.Contains(product, "virtual machine"):
		return state.VirtHyperV
	case strings.Contains(vendor, "xen"), strings.Contains(product, "hvm domu"):
		return state.VirtXen
	case strings.Contains(product, "kvm"), strings.Contains(vendor, "openstack"), strings.Contains(product, "openstack"):
		return state.VirtKVM
	case vendor == "qemu", strings.Contains(product, "qemu"), strings.Contains(product, "standard pc"):
		return state.VirtQEMU
	case vendor == "amazon ec2" && !strings.HasSuffix(product, ".metal"):
		return state.VirtKVM
	case vendor == "google" && strings.Contains(product, "compute engine"):
		return state.VirtKVM
	case vendor == "hetzner" && strings.Contains(product, "vserver"):
		return state.VirtKVM
	case vendor == "digitalocean", vendor == "vultr", vendor == "linode", vendor == "akamai", vendor == "scaleway",
		vendor == "upcloud", vendor == "exoscale", strings.Contains(vendor, "oraclecloud"):
		return state.VirtKVM
	}
	return ""
}

// inContainer: launchroomd runs in a container (/.dockerenv or podman's
// /run/.containerenv) whose PID 1 is neither launchroomd itself nor a host
// init, i.e. it shares a container's PID namespace instead of the host's.
func inContainer(o HardwareOptions) bool {
	if o.Pid == 1 {
		return false // no pid: host; nothing can be said
	}
	marker := false
	for _, f := range []string{".dockerenv", filepath.Join("run", ".containerenv")} {
		if _, err := os.Stat(filepath.Join(o.RootDir, f)); err == nil {
			marker = true
		}
	}
	if !marker {
		return false
	}
	comm := strings.TrimSpace(string(readText(filepath.Join(o.ProcRoot, "1", "comm"))))
	return comm != "" && !hostInits[comm]
}

// hostTimezone: the installer-provided zone, then the host's (via /proc/1/root,
// readable when launchroomd shares the host's PID namespace and may trace its
// init), then launchroomd's own. UTC and Etc/* say nothing about a location
// and are dropped.
func hostTimezone(o HardwareOptions) state.Timezone {
	host := filepath.Join(o.ProcRoot, "1", "root", "etc")
	own := filepath.Join(o.RootDir, "etc")
	candidates := []func() string{
		func() string { return o.Timezone },
		func() string { return string(readText(filepath.Join(host, "timezone"))) },
		func() string { return zoneFromLink(filepath.Join(host, "localtime")) },
		func() string { return string(readText(filepath.Join(own, "timezone"))) },
		func() string { return zoneFromLink(filepath.Join(own, "localtime")) },
	}
	for _, c := range candidates {
		if tz := ValidTimezone(c()); tz != "" {
			return tz
		}
	}
	return ""
}

// ValidTimezone returns tz when it is an IANA zone that says something about
// a location ("" for UTC, Etc/*, Factory and invalid names).
func ValidTimezone(tz string) state.Timezone {
	tz = strings.TrimSpace(tz)
	switch {
	case tz == "", strings.EqualFold(tz, "UTC"), strings.EqualFold(tz, "UCT"), strings.EqualFold(tz, "GMT"),
		strings.EqualFold(tz, "Zulu"), strings.EqualFold(tz, "Universal"), strings.EqualFold(tz, "Factory"),
		strings.HasPrefix(tz, "Etc/"), strings.HasPrefix(tz, "posix/"), strings.HasPrefix(tz, "right/"):
		return ""
	}
	v := state.Timezone(tz)
	if v.Validate() != nil {
		return ""
	}
	return v
}

func zoneFromLink(path string) string {
	target, err := os.Readlink(path)
	if err != nil {
		return ""
	}
	_, zone, ok := strings.Cut(target, "zoneinfo/")
	if !ok {
		return ""
	}
	return zone
}

func readLimited(path string) ([]byte, error) {
	f, err := os.Open(path) //nolint:gosec // fixed /proc and /sys paths
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	return io.ReadAll(io.LimitReader(f, 1<<20))
}

func readText(path string) state.FactText {
	data, err := readLimited(path)
	if err != nil {
		return ""
	}
	return FactText(string(data))
}

func firstText(vs ...state.FactText) state.FactText {
	for _, v := range vs {
		if v != "" {
			return v
		}
	}
	return ""
}

// FactText sanitizes s for the protocol: valid UTF-8, control characters
// (including the device tree's trailing NUL) removed, spaces collapsed,
// at most 128 bytes cut on a rune boundary. Placeholders firmware vendors
// leave in DMI ("To be filled by O.E.M.", "Default string") become "".
func FactText(s string) state.FactText {
	s = strings.ToValidUTF8(s, "")
	s = strings.Map(func(r rune) rune {
		if unicode.IsControl(r) {
			return ' '
		}
		return r
	}, s)
	s = strings.Join(strings.Fields(s), " ")
	for len(s) > 128 {
		_, size := utf8.DecodeLastRuneInString(s)
		s = s[:len(s)-size]
	}
	s = strings.TrimSpace(s)
	switch strings.ToLower(s) {
	case "to be filled by o.e.m.", "default string", "not specified", "none", "n/a", "system product name",
		"system manufacturer", "o.e.m.", "oem", "unknown", "type1productconfigid", "type2 - board vendor name1":
		return ""
	}
	if state.FactText(s).Validate() != nil {
		return ""
	}
	return state.FactText(s)
}

// MemoryTotalMB is MemTotal from <procRoot>/meminfo (0 if unknown).
func MemoryTotalMB(procRoot string) int {
	m := (&Collector{o: Options{ProcRoot: procRoot}}).memory()
	if m == nil {
		return 0
	}
	return int(m.TotalMB)
}

// HardwareTracker decides when reports carry the hardware facts: when they
// changed since the last acknowledged report, were never acknowledged, or the
// last acknowledgement is an hour old (a dashboard upgraded after the daemon
// learns them without a daemon restart).
type HardwareTracker struct {
	mu      sync.Mutex
	cur     *state.HardwareFacts
	curKey  string
	ackKey  string
	ackedAt time.Time
}

// HardwareResend is how often unchanged facts are sent again.
const HardwareResend = time.Hour

// Set records the latest facts (nil keeps the previous ones).
func (t *HardwareTracker) Set(h *state.HardwareFacts) {
	if h == nil {
		return
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	t.cur, t.curKey = h, factsKey(h)
}

// ForReport returns the facts to attach to a report at now, or nil.
func (t *HardwareTracker) ForReport(now time.Time) *state.HardwareFacts {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.cur == nil {
		return nil
	}
	if t.curKey != t.ackKey || now.Sub(t.ackedAt) >= HardwareResend {
		return t.cur
	}
	return nil
}

// Ack records that a report carrying h was accepted at now.
func (t *HardwareTracker) Ack(h *state.HardwareFacts, now time.Time) {
	if h == nil {
		return
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	t.ackKey, t.ackedAt = factsKey(h), now
}

func factsKey(h *state.HardwareFacts) string {
	b, _ := json.Marshal(h)
	return string(b)
}
