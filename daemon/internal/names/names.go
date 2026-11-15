// Package names owns the identity of launchroom's Docker objects: label keys
// and values, the generated host-side names and their grammars (docs/09 R3).
// It is a leaf, so dockerd-guard (a separate trust boundary) checks requests
// against the same values the packages that create the objects use.
package names

import (
	"crypto/rand"
	"encoding/hex"
	"regexp"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Labels on launchroom-managed objects. launchroomd only ever lists, changes
// or removes objects carrying LabelManaged=true (AGENTS.md, docs/03 §1).
const (
	LabelManaged     = "launchroom.managed"
	LabelServer      = "launchroom.server"
	LabelWorkload    = "launchroom.workload"
	LabelService     = "launchroom.service"
	LabelEnvironment = "launchroom.environment"
	LabelDeployment  = "launchroom.deployment"
	// LabelSystem marks launchroom's own system containers; the value is the
	// component (SystemCaddy, …).
	LabelSystem = "launchroom.system"
	// LabelSpecHash is a digest of everything the container was created from;
	// a different desired hash means the container must be recreated.
	LabelSpecHash = "launchroom.spec-hash"
	// LabelNetwork carries the protocol id of a managed network.
	LabelNetwork = "launchroom.network"
	// LabelVolume carries the protocol id (or system purpose) of a managed volume.
	LabelVolume = "launchroom.volume"
	// LabelTask marks a one-off container of a run_once task (no workload
	// label: the reconciler never lists, changes or removes it).
	LabelTask = "launchroom.task"
	// LabelHelper marks a helper container; the value is HelperVolume or HelperAccess.
	LabelHelper = "launchroom.helper"
	// LabelAccess carries the access id on a volume access helper.
	LabelAccess = "launchroom.access"
	// LabelRole and LabelUpdateTask mark the self-update supervisor
	// (RoleUpdater); it carries no server label (docs/11 §4).
	LabelRole       = "launchroom.role"
	LabelUpdateTask = "launchroom.update-task"
)

// Label values.
const (
	HelperVolume = "volume"
	HelperAccess = "access"
	RoleUpdater  = "updater"
)

// System components: NodeState.system keys and LabelSystem values.
const (
	SystemCaddy     = "caddy"
	SystemBuildkit  = "buildkit"
	SystemTailscale = "tailscale"
	// SystemFiles is the SFTP helper of volume access (D67).
	SystemFiles = "files"
	// SystemAmbassador is built locally from launchroomd's own binary, so
	// the dashboard does not choose its image (docs/05 §3).
	SystemAmbassador = "ambassador"
)

// Host-side name prefixes. The dashboard generates lr-e_<hash> environment
// networks and lr-v_<hash> per-service volumes; lr-system-* are the daemon's
// own objects and are never part of a NodeState.
const (
	Prefix             = "lr-"
	NetworkPrefix      = "lr-e_"
	VolumePrefix       = "lr-v_"
	SystemPrefix       = "lr-system-"
	RunOncePrefix      = "lr-run-"
	VolumeHelperPrefix = "lr-vol-"
	AccessHelperPrefix = "lr-acc-"
	UpdaterPrefix      = SystemPrefix + "update-"
	// LocalImagePrefix is the repository prefix of images launchroomd
	// imports itself (the volume helper).
	LocalImagePrefix = "launchroom-local/"
)

// System objects.
const (
	CaddyContainer         = SystemPrefix + "caddy"
	CaddyDataVolume        = SystemPrefix + "caddy-data"
	CaddyConfigVolume      = SystemPrefix + "caddy-config"
	TailscaleContainer     = SystemPrefix + "tailscale"
	TailscaleStateVolume   = SystemPrefix + "tailscale-state"
	BuildkitContainer      = SystemPrefix + "buildkit"
	BuildkitNetwork        = SystemPrefix + "build"
	BuildkitStateVolume    = SystemPrefix + "buildkit-state"
	AmbassadorContainer    = SystemPrefix + "ambassador"
	AmbassadorNetwork      = SystemPrefix + "ambassador"
	AmbassadorConfigVolume = SystemPrefix + "ambassador-config"
	AmbassadorImageRepo    = SystemPrefix + "ambassador"
	// BuildkitAppArmorProfile is the host profile the installers load on
	// hosts with kernel.apparmor_restrict_unprivileged_userns=1 (D58).
	BuildkitAppArmorProfile = "launchroom-buildkit"
	// VolumeHelperImage is the image of volume helper containers. Volume
	// backups (docs/14 §2) read and write named volumes through a helper
	// that is created but never started: the Engine API's archive endpoints
	// stream the volume's content, so the image only holds the mount point
	// and is imported locally.
	VolumeHelperImage = LocalImagePrefix + "volume-helper:1"
	// HelperMount is where volume and access helpers mount their volume.
	HelperMount = "/data"
)

// The self-update supervisor runs launchroomd's own binary with this
// subcommand (docs/11 §4); the guard allows exactly this entrypoint.
const (
	SupervisorBinary  = "/usr/local/bin/launchroomd"
	SupervisorCommand = "update-supervisor"
)

// Grammars of generated names (the guard checks requests against them).
var (
	// ReObjectName is any launchroom-managed container, network or volume name.
	ReObjectName   = regexp.MustCompile(`^lr-[a-z0-9][a-z0-9_.-]{0,120}$`)
	ReVolumeHelper = regexp.MustCompile(`^` + VolumeHelperPrefix + `[a-f0-9]{12}$`)
	ReAccessHelper = regexp.MustCompile(`^` + AccessHelperPrefix + `[a-f0-9]{12}$`)
	ReUpdater      = regexp.MustCompile(`^` + UpdaterPrefix + `[a-z0-9]{26}$`)
)

// Managed reports whether name carries launchroom's prefix.
func Managed(name string) bool { return strings.HasPrefix(name, Prefix) }

// System reports whether name is one of launchroomd's own objects.
func System(name string) bool { return strings.HasPrefix(name, SystemPrefix) }

// WorkloadContainer is the container name of a workload.
func WorkloadContainer(id state.WorkloadID) string { return Prefix + strings.ToLower(string(id)) }

// RunOnceContainer is the container name of a run_once task.
func RunOnceContainer(id state.TaskID) string { return RunOncePrefix + state.LowerID(id) }

// Updater is the container name of the self-update supervisor of task id.
func Updater(id state.TaskID) string {
	return UpdaterPrefix + strings.ToLower(strings.TrimPrefix(string(id), "tsk_"))
}

// UpdaterStep is the container name of one step the supervisor runs.
func UpdaterStep(id state.TaskID, step string) string { return Updater(id) + "-" + step }

// VolumeHelper returns a fresh volume helper container name.
func VolumeHelper() string { return VolumeHelperPrefix + randomHex(6) }

// AccessHelper returns a fresh access helper container name.
func AccessHelper() string { return AccessHelperPrefix + randomHex(6) }

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}
