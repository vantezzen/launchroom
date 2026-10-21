// Command releasesign signs release bundles with a throwaway key for the
// control-plane update end-to-end test (`make test-update`,
// deploy/test/e2e-update.sh): one ephemeral ECDSA P-256 key per run, a
// sigstore bundle (<file>.sigstore.json) next to every file, and the public
// key as PEM for trust.json's key-based mode (docs/11 §4, private builds and
// tests only). Dev tooling only; it is not shipped.
//
//	releasesign PUBLIC_KEY_OUT FILE...
package main

import (
	"fmt"
	"os"

	"github.com/vantezzen/launchroom/daemon/internal/update/updatetest"
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "releasesign:", err)
		os.Exit(1)
	}
}

func run(args []string) error {
	if len(args) < 2 {
		return fmt.Errorf("usage: releasesign PUBLIC_KEY_OUT FILE")
	}
	s, err := updatetest.NewSigner()
	if err != nil {
		return err
	}
	for _, f := range args[1:] {
		b, err := os.ReadFile(f) //nolint:gosec // dev tool: signs the files it is given
		if err != nil {
			return err
		}
		sig, err := s.Sign(b)
		if err != nil {
			return err
		}
		if err := os.WriteFile(f+".sigstore.json", sig, 0o644); err != nil { //nolint:gosec // public release assets
			return err
		}
	}
	return os.WriteFile(args[0], []byte(s.PublicKeyPEM), 0o644) //nolint:gosec // a public key
}
