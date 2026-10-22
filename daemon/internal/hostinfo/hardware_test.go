package hostinfo

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func hardwareFor(t *testing.T, name string, mod func(*HardwareOptions)) *state.HardwareFacts {
	t.Helper()
	dir := filepath.Join("testdata", "hw", name)
	o := HardwareOptions{
		ProcRoot: filepath.Join(dir, "proc"), SysRoot: filepath.Join(dir, "sys"), RootDir: filepath.Join(dir, "root"), Pid: 4242,
	}
	if mod != nil {
		mod(&o)
	}
	h := Hardware(context.Background(), o)
	if h == nil {
		t.Fatalf("%s: no facts", name)
	}
	if err := h.Validate(); err != nil {
		t.Fatalf("%s: %v", name, err)
	}
	raw, _ := json.Marshal(h)
	if len(raw) > 2048 {
		t.Fatalf("%s: facts are %d bytes", name, len(raw))
	}
	if strings.Contains(strings.ToLower(string(raw)), "secret") || strings.Contains(string(raw), "0123456789abcdef") {
		t.Fatalf("%s: serials leaked: %s", name, raw)
	}
	return h
}

func TestHardwareRaspberryPi(t *testing.T) {
	h := hardwareFor(t, "pi5", nil)
	if h.CPUModel != "Cortex-A76" || h.CPUVendor != "ARM" {
		t.Errorf("cpu %q %q", h.CPUModel, h.CPUVendor)
	}
	if h.DeviceTreeModel != "Raspberry Pi 5 Model B Rev 1.0" {
		t.Errorf("device tree %q", h.DeviceTreeModel)
	}
	if h.DMI != nil || h.Virtualization != "" || h.Timezone != "Europe/Berlin" {
		t.Errorf("%+v", h)
	}
}

func TestHardwareRaspberryPiModelFromCPUInfo(t *testing.T) {
	// Docker masks /sys/firmware and the device tree may be missing: the
	// cpuinfo "Model" line stands in.
	cpu := readCPUInfo(filepath.Join("testdata", "hw", "pi5", "proc"))
	if cpu.boardModel != "Raspberry Pi 5 Model B Rev 1.0" {
		t.Fatalf("board model %q", cpu.boardModel)
	}
}

func TestHardwareHetznerCloud(t *testing.T) {
	h := hardwareFor(t, "hetzner", func(o *HardwareOptions) {
		o.Engine = func(context.Context) (docker.EngineInfo, error) {
			return docker.EngineInfo{OperatingSystem: "Ubuntu 24.04.1 LTS", KernelVersion: "6.8.0-45-generic"}, nil
		}
		o.Timezone = "Etc/UTC"
	})
	if h.CPUModel != "AMD EPYC-Rome Processor" || h.CPUVendor != "AuthenticAMD" {
		t.Errorf("cpu %+v", h)
	}
	want := state.DMIFacts{SysVendor: "Hetzner", ProductName: "vServer", ProductFamily: "Hetzner_vServer", BoardVendor: "Hetzner", BIOSVendor: "Hetzner", ChassisType: 1}
	if h.DMI == nil || *h.DMI != want {
		t.Errorf("dmi %+v", h.DMI)
	}
	if h.Virtualization != state.VirtKVM || h.OSName != "Ubuntu 24.04.1 LTS" || h.Kernel != "6.8.0-45-generic" || h.Timezone != "" {
		t.Errorf("%+v", h)
	}
}

func TestHardwareVirtualBox(t *testing.T) {
	h := hardwareFor(t, "vbox", nil)
	if h.Virtualization != state.VirtVirtualBox || h.DMI.ProductName != "VirtualBox" {
		t.Errorf("%+v %+v", h, h.DMI)
	}
}

func TestHardwareLaptop(t *testing.T) {
	h := hardwareFor(t, "laptop", func(o *HardwareOptions) { o.Kernel = func() string { return "6.11.0\x00" } })
	if h.DMI.ChassisType != 10 || h.DMI.SysVendor != "LENOVO" || h.DMI.BoardName != "" {
		t.Errorf("dmi %+v (OEM placeholders must be dropped)", h.DMI)
	}
	if h.Virtualization != "" || h.Kernel != "6.11.0" {
		t.Errorf("%+v", h)
	}
	if h.Timezone != "America/New_York" {
		t.Errorf("timezone from the host's /etc/localtime link: %q", h.Timezone)
	}
	if !h.Battery {
		t.Error("BAT0 (scope System) is a system battery")
	}
}

func TestHardwareDindIsContainer(t *testing.T) {
	h := hardwareFor(t, "dind", func(o *HardwareOptions) {
		o.Engine = func(context.Context) (docker.EngineInfo, error) {
			return docker.EngineInfo{OperatingSystem: "Alpine Linux v3.24 (containerized)", KernelVersion: "7.0.5-orbstack"}, nil
		}
	})
	if h.Virtualization != state.VirtContainer || h.CPUVendor != "Apple" {
		t.Errorf("%+v", h)
	}
}

func TestHardwareProductionContainerOnMetal(t *testing.T) {
	// launchroomd's own container has /.dockerenv, but with pid: host PID 1
	// is the host's systemd: not a container host.
	h := hardwareFor(t, "metal", nil)
	if h.Virtualization != "" || h.DMI.ChassisType != 17 {
		t.Errorf("%+v", h)
	}
	if h.Battery {
		t.Error("a wireless mouse battery (scope Device) is not a system battery")
	}
	if h := hardwareFor(t, "dind", func(o *HardwareOptions) { o.Pid = 1 }); h.Virtualization != "" {
		t.Errorf("pid 1 without pid: host must not be called a container: %+v", h)
	}
}

func TestHardwareDockerDesktop(t *testing.T) {
	desktop := func(kernel string) func(*HardwareOptions) {
		return func(o *HardwareOptions) {
			o.RootDir = t.TempDir()
			o.Engine = func(context.Context) (docker.EngineInfo, error) {
				return docker.EngineInfo{OperatingSystem: "Docker Desktop", KernelVersion: kernel}, nil
			}
		}
	}
	if h := hardwareFor(t, "dind", desktop("6.10.14-linuxkit")); h.Virtualization != state.VirtApple {
		t.Errorf("mac: %+v", h)
	}
	if h := hardwareFor(t, "dind", desktop("5.15.153.1-microsoft-standard-WSL2")); h.Virtualization != state.VirtHyperV {
		t.Errorf("windows: %+v", h)
	}
}

func TestHardwareOrbStackMac(t *testing.T) {
	// launchroomd on OrbStack (D53): /.dockerenv, and with pid: host PID 1 is
	// OrbStack's own init, not a host init. The engine's name wins over the
	// container heuristic; the installer's model hint is kept.
	h := hardwareFor(t, "dind", func(o *HardwareOptions) {
		o.Engine = func(context.Context) (docker.EngineInfo, error) {
			return docker.EngineInfo{OperatingSystem: "OrbStack", KernelVersion: "7.0.5-orbstack-00330"}, nil
		}
		o.HostModel = "Mac mini\n"
	})
	if h.Virtualization != state.VirtApple || h.HostModel != "Mac mini" {
		t.Errorf("%+v", h)
	}
	if h := hardwareFor(t, "dind", func(o *HardwareOptions) { o.HostModel = "\x00\x01" }); h.HostModel != "" {
		t.Errorf("control characters only: %+v", h)
	}
}

func TestHardwareNothingReadable(t *testing.T) {
	d := t.TempDir()
	if h := Hardware(context.Background(), HardwareOptions{ProcRoot: d, SysRoot: d, RootDir: d, Pid: 7}); h != nil {
		t.Fatalf("%+v", h)
	}
}

func TestDMIVirtualization(t *testing.T) {
	cases := []struct {
		d    state.DMIFacts
		want state.Virtualization
	}{
		{state.DMIFacts{SysVendor: "QEMU", ProductName: "Standard PC (Q35 + ICH9, 2009)"}, state.VirtQEMU},
		{state.DMIFacts{SysVendor: "DigitalOcean", ProductName: "Droplet"}, state.VirtKVM},
		{state.DMIFacts{SysVendor: "Amazon EC2", ProductName: "t3.micro"}, state.VirtKVM},
		{state.DMIFacts{SysVendor: "Amazon EC2", ProductName: "m5.metal"}, ""},
		{state.DMIFacts{SysVendor: "Google", ProductName: "Google Compute Engine"}, state.VirtKVM},
		{state.DMIFacts{SysVendor: "Microsoft Corporation", ProductName: "Virtual Machine"}, state.VirtHyperV},
		{state.DMIFacts{SysVendor: "VMware, Inc.", ProductName: "VMware Virtual Platform"}, state.VirtVMware},
		{state.DMIFacts{SysVendor: "Parallels Software International Inc.", ProductName: "Parallels Virtual Platform"}, state.VirtParallels},
		{state.DMIFacts{SysVendor: "Apple Inc.", ProductName: "Apple Virtualization Generic Platform"}, state.VirtApple},
		{state.DMIFacts{SysVendor: "Xen", ProductName: "HVM domU"}, state.VirtXen},
		{state.DMIFacts{SysVendor: "Apple Inc.", ProductName: "Macmini9,1"}, ""},
		{state.DMIFacts{SysVendor: "Synology Inc.", ProductName: "DS920+"}, ""},
	}
	for _, c := range cases {
		if got := dmiVirtualization(&c.d); got != c.want {
			t.Errorf("%+v: got %q want %q", c.d, got, c.want)
		}
	}
}

func TestFactTextSanitizes(t *testing.T) {
	long := strings.Repeat("é", 100) // 200 bytes
	for in, want := range map[string]state.FactText{
		"  Intel(R)\tXeon(R)  ": "Intel(R) Xeon(R)",
		"Pi\x00":                "Pi",
		"Default string":        "",
		"\xff\xfe":              "",
		long:                    state.FactText(strings.Repeat("é", 64)),
	} {
		if got := FactText(in); got != want {
			t.Errorf("FactText(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestValidTimezone(t *testing.T) {
	for in, want := range map[string]state.Timezone{
		"Europe/Berlin": "Europe/Berlin", "America/Argentina/Buenos_Aires": "America/Argentina/Buenos_Aires",
		"UTC": "", "Etc/UTC": "", "Etc/GMT+2": "", "../etc/passwd": "", "": "", "Europe/Berlin; rm": "",
	} {
		if got := ValidTimezone(in); got != want {
			t.Errorf("ValidTimezone(%q) = %q", in, got)
		}
	}
}

func TestHardwareTracker(t *testing.T) {
	var tr HardwareTracker
	at := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	if tr.ForReport(at) != nil {
		t.Fatal("nothing collected yet")
	}
	a := &state.HardwareFacts{CPUModel: "A"}
	tr.Set(a)
	if tr.ForReport(at) != a {
		t.Fatal("never acknowledged: must be sent")
	}
	tr.Ack(a, at)
	if tr.ForReport(at.Add(time.Minute)) != nil {
		t.Fatal("acknowledged and unchanged: not sent")
	}
	tr.Set(&state.HardwareFacts{CPUModel: "A"}) // same content
	if tr.ForReport(at.Add(time.Minute)) != nil {
		t.Fatal("equal facts are not a change")
	}
	if tr.ForReport(at.Add(HardwareResend)) == nil {
		t.Fatal("resent hourly")
	}
	b := &state.HardwareFacts{CPUModel: "A", Timezone: "Europe/Berlin"}
	tr.Set(b)
	if tr.ForReport(at.Add(2*time.Minute)) != b {
		t.Fatal("changed facts are sent")
	}
	tr.Ack(a, at.Add(2*time.Minute)) // an older report was acknowledged meanwhile
	if tr.ForReport(at.Add(3*time.Minute)) != b {
		t.Fatal("the change is not acknowledged yet")
	}
}

func TestMemoryTotalMB(t *testing.T) {
	if got := MemoryTotalMB("testdata/proc"); got != 7939 {
		t.Fatalf("got %d", got)
	}
	if MemoryTotalMB(t.TempDir()) != 0 {
		t.Fatal("unknown must be 0")
	}
}

func TestReadMemInfo(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "meminfo"), []byte("MemTotal:        2014312 kB\nMemFree:          100000 kB\nMemAvailable:     870000 kB\nSwapTotal:       2097148 kB\nSwapFree:        2000000 kB\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	m, ok := ReadMemInfo(dir)
	if !ok || m.TotalMB != 1967 || m.AvailableMB != 849 || m.SwapTotalMB != 2047 || m.SwapFreeMB != 1953 {
		t.Fatalf("%+v %v", m, ok)
	}
	// No swap lines (containers with LXCFS, old kernels): zero swap.
	if m, ok := ReadMemInfo("testdata/proc"); !ok || m.SwapTotalMB != 0 || m.TotalMB != 7939 {
		t.Fatalf("%+v %v", m, ok)
	}
	if _, ok := ReadMemInfo(t.TempDir()); ok {
		t.Fatal("missing meminfo read")
	}
}
