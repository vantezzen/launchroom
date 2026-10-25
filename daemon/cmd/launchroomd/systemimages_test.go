package main

import (
	"bytes"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
)

func TestPrintSystemImages(t *testing.T) {
	var out bytes.Buffer
	printSystemImages(&out)
	want := "buildkit=" + policy.BuildkitImageDigest + "\n" +
		"caddy=" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0] + "\n" +
		"files=" + policy.FilesImageDigest + "\n" +
		"tailscale=" + policy.TailscaleImageDigest + "\n"
	if out.String() != want {
		t.Fatalf("got\n%s\nwant\n%s", out.String(), want)
	}
}
