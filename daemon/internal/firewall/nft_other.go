//go:build !linux

package firewall

import "errors"

var errUnsupported = errors.New("firewall: nftables is only available on Linux")

type unsupportedBackend struct{}

// NewBackend returns a backend that always fails (nftables needs Linux).
func NewBackend() Backend { return unsupportedBackend{} }

func (unsupportedBackend) Apply(Ruleset) error             { return errUnsupported }
func (unsupportedBackend) Check(Ruleset) ([]string, error) { return nil, errUnsupported }
func (unsupportedBackend) Remove() error                   { return errUnsupported }
