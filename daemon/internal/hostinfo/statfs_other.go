//go:build !linux

package hostinfo

import "github.com/vantezzen/launchroom/daemon/internal/state"

// Filesystem is only implemented on Linux.
func Filesystem(string) (state.FilesystemUsage, bool) { return state.FilesystemUsage{}, false }
