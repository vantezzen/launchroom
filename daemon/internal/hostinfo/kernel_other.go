//go:build !linux

package hostinfo

// KernelRelease is only implemented on Linux.
func KernelRelease() string { return "" }
