package hostinfo

import "golang.org/x/sys/unix"

// KernelRelease is the kernel release from uname(2) ("" if unknown).
func KernelRelease() string {
	var u unix.Utsname
	if err := unix.Uname(&u); err != nil {
		return ""
	}
	return unix.ByteSliceToString(u.Release[:])
}
