//go:build ignore

// gen_trusted_root fetches the Sigstore public-good trusted root
// (Fulcio CAs, Rekor/CT log keys, TSAs) through TUF, rooted in the TUF root
// that sigstore-go embeds, and writes it to trusted_root.json. launchroomd
// embeds that file as its offline fallback (verify.go); at runtime it
// prefers a fresh copy from TUF.
//
//	go generate ./internal/update
package main

import (
	"fmt"
	"os"

	"github.com/sigstore/sigstore-go/pkg/root"
	"github.com/sigstore/sigstore-go/pkg/tuf"
)

func main() {
	dir, err := os.MkdirTemp("", "lr-tuf-")
	if err != nil {
		panic(err)
	}
	defer os.RemoveAll(dir)
	c, err := tuf.New(tuf.DefaultOptions().WithCachePath(dir))
	if err != nil {
		panic(err)
	}
	raw, err := c.GetTarget("trusted_root.json")
	if err != nil {
		panic(err)
	}
	if _, err := root.NewTrustedRootFromJSON(raw); err != nil {
		panic(fmt.Errorf("fetched trusted root does not parse: %w", err))
	}
	if err := os.WriteFile("trusted_root.json", raw, 0o644); err != nil { //nolint:gosec // public data
		panic(err)
	}
	fmt.Printf("wrote trusted_root.json (%d bytes)\n", len(raw))
}
