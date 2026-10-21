package hostinfo

import (
	"syscall"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Filesystem returns the size and free space (available to unprivileged
// users) of the filesystem holding path; ok=false when it cannot be read.
func Filesystem(path string) (state.FilesystemUsage, bool) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil || st.Blocks == 0 {
		return state.FilesystemUsage{}, false
	}
	bs := st.Bsize
	return state.FilesystemUsage{
		TotalMB: int64(st.Blocks) * bs >> 20, //nolint:gosec // block counts fit in int64
		FreeMB:  int64(st.Bavail) * bs >> 20, //nolint:gosec // block counts fit in int64
	}, true
}
