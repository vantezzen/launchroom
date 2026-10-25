package reconcile

import (
	"testing"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestSpecHashCoversConfigFiles(t *testing.T) {
	key := EnvHashKey([]byte("k"))
	mk := func(files []state.ConfigFile) string {
		h, err := SpecHash(&docker.ContainerCreate{Name: "lr-x", Config: &container.Config{Image: "i"}, HostConfig: &container.HostConfig{}, Files: files}, key)
		if err != nil {
			t.Fatal(err)
		}
		return h
	}
	none := mk(nil)
	a := mk([]state.ConfigFile{{Path: "/a.conf", Content: "1"}})
	b := mk([]state.ConfigFile{{Path: "/a.conf", Content: "2"}})
	c := mk([]state.ConfigFile{{Path: "/b.conf", Content: "1"}})
	if none == a || a == b || a == c || a != mk([]state.ConfigFile{{Path: "/a.conf", Content: "1"}}) {
		t.Fatalf("hashes %s %s %s %s", none, a, b, c)
	}
	if none != mk([]state.ConfigFile{}) {
		t.Fatal("an empty file list changed the hash")
	}
}
