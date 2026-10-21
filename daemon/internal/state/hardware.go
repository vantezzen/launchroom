package state

import "regexp"

// Hardware facts (docs/design/direction.md §7.3): what launchroomd can read
// about the machine from local files and syscalls, so the dashboard can show
// the right device and provider. No network calls (no cloud metadata
// services), no shell, never serial numbers or UUIDs. Every field is
// optional: what cannot be read is omitted, never guessed.

const maxFactTextLen = 128

var (
	// reFactText is printable text without control characters.
	reFactText = regexp.MustCompile(`^[^\x00-\x1f\x7f]{1,128}$`)
	// reTimezone is an IANA zone name such as Europe/Berlin or America/Argentina/Buenos_Aires.
	reTimezone = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9_+-]{0,31}(/[A-Za-z0-9_+-]{1,32}){0,2}$`)
)

// FactText is a short, printable, informational string read from the host
// (CPU model, DMI vendor). Display only; never used as an identifier.
type FactText string

// ProtocolSchema implements SchemaDescriber.
func (FactText) ProtocolSchema() ScalarSchema { return strSchema(reFactText, maxFactTextLen) }

// Validate checks the grammar.
func (v FactText) Validate() error {
	return checkPattern("fact", string(v), reFactText, maxFactTextLen)
}

// Timezone is an IANA time zone name.
type Timezone string

// ProtocolSchema implements SchemaDescriber.
func (Timezone) ProtocolSchema() ScalarSchema { return strSchema(reTimezone, 64) }

// Validate checks the grammar.
func (v Timezone) Validate() error { return checkPattern("timezone", string(v), reTimezone, 64) }

// Virtualization is the hypervisor (or container runtime) the host runs
// under, as far as launchroomd can tell.
type Virtualization string

// Virtualization kinds.
const (
	VirtKVM        Virtualization = "kvm"
	VirtQEMU       Virtualization = "qemu"
	VirtVMware     Virtualization = "vmware"
	VirtVirtualBox Virtualization = "virtualbox"
	VirtXen        Virtualization = "xen"
	VirtHyperV     Virtualization = "hyperv"
	VirtParallels  Virtualization = "parallels"
	VirtApple      Virtualization = "apple"
	VirtBhyve      Virtualization = "bhyve"
	// VirtContainer: launchroomd's "host" is itself a container (dind, LXC).
	VirtContainer Virtualization = "container"
	// VirtOther: virtualized (CPU hypervisor flag), hypervisor unknown.
	VirtOther Virtualization = "other"
)

var virtualizations = []string{
	string(VirtKVM), string(VirtQEMU), string(VirtVMware), string(VirtVirtualBox), string(VirtXen), string(VirtHyperV),
	string(VirtParallels), string(VirtApple), string(VirtBhyve), string(VirtContainer), string(VirtOther),
}

// ProtocolSchema implements SchemaDescriber.
func (Virtualization) ProtocolSchema() ScalarSchema { return enumSchema(virtualizations) }

// Validate checks the value.
func (v Virtualization) Validate() error {
	return checkEnum("virtualization", string(v), virtualizations)
}

// HardwareFacts describe the machine launchroomd runs on. Sent at enrollment
// (EnrollRequest.host.hardware) and in reports (Report.host.hardware) when
// they changed or were not acknowledged yet, and at least hourly.
type HardwareFacts struct {
	// CPUModel is /proc/cpuinfo "model name" (ARM: "Hardware"/"Model").
	CPUModel FactText `json:"cpuModel,omitzero"`
	// CPUVendor is /proc/cpuinfo "vendor_id" (ARM: the CPU implementer, e.g. "ARM", "Apple").
	CPUVendor FactText `json:"cpuVendor,omitzero"`
	// DMI are the world-readable fields of /sys/class/dmi/id (no serials, no UUIDs).
	DMI *DMIFacts `json:"dmi,omitzero" jsonschema:"nullable"`
	// DeviceTreeModel is the device-tree model (single-board computers, Apple Silicon under Asahi).
	DeviceTreeModel FactText `json:"deviceTreeModel,omitzero"`
	// Virtualization is omitted when no hypervisor was detected.
	Virtualization Virtualization `json:"virtualization,omitzero"`
	// OSName is the host operating system as Docker reports it (e.g. "Ubuntu 24.04.1 LTS", "Docker Desktop").
	OSName FactText `json:"osName,omitzero"`
	// Kernel is the kernel release (uname).
	Kernel FactText `json:"kernel,omitzero"`
	// Timezone is the host's IANA time zone; omitted when unknown or UTC.
	Timezone Timezone `json:"timezone,omitzero"`
	// Battery is true when the machine has a system battery (a power supply
	// of type Battery whose scope is not Device, so a wireless mouse
	// doesn't count): laptops. Omitted when there is none.
	Battery bool `json:"battery,omitzero"`
	// HostModel is the machine model the installer read on the host where
	// launchroomd's VM hides it (macOS: system_profiler's "Model Name",
	// e.g. "Mac mini", "MacBook Pro"; LR_HOST_MODEL, D53). Display only.
	HostModel FactText `json:"hostModel,omitzero"`
}

// DMIFacts are SMBIOS fields from /sys/class/dmi/id.
type DMIFacts struct {
	SysVendor     FactText `json:"sysVendor,omitzero"`
	ProductName   FactText `json:"productName,omitzero"`
	ProductFamily FactText `json:"productFamily,omitzero"`
	BoardVendor   FactText `json:"boardVendor,omitzero"`
	BoardName     FactText `json:"boardName,omitzero"`
	BIOSVendor    FactText `json:"biosVendor,omitzero"`
	// ChassisType is the SMBIOS chassis type (3 desktop, 10 notebook, 17 server, 23 rack mount, 35 mini PC, …).
	ChassisType int `json:"chassisType,omitzero" jsonschema:"minimum=0,maximum=255"`
}

func (h *HardwareFacts) validate(e *errs, p string) {
	text := map[string]FactText{
		".cpuModel": h.CPUModel, ".cpuVendor": h.CPUVendor, ".deviceTreeModel": h.DeviceTreeModel,
		".osName": h.OSName, ".kernel": h.Kernel, ".hostModel": h.HostModel,
	}
	if d := h.DMI; d != nil {
		for k, v := range map[string]FactText{
			"sysVendor": d.SysVendor, "productName": d.ProductName, "productFamily": d.ProductFamily,
			"boardVendor": d.BoardVendor, "boardName": d.BoardName, "biosVendor": d.BIOSVendor,
		} {
			text[".dmi."+k] = v
		}
		if d.ChassisType < 0 || d.ChassisType > 255 {
			e.addf(p+".dmi.chassisType", "must be between 0 and 255")
		}
	}
	for k, v := range text {
		if v != "" {
			e.check(p+k, v)
		}
	}
	if h.Virtualization != "" {
		e.check(p+".virtualization", h.Virtualization)
	}
	if h.Timezone != "" {
		e.check(p+".timezone", h.Timezone)
	}
}

// Validate checks the facts (also used by launchroomd before sending them).
func (h *HardwareFacts) Validate() error {
	var e errs
	h.validate(&e, "hardware")
	return e.err()
}
