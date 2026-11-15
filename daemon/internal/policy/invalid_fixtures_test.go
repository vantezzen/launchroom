package policy

import (
	stdjson "encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestInvalidNodeStateFixtures replays the shared invalid NodeStates whose
// rule is the policy floor's (protocol/fixtures/v1/invalid, enforcedBy
// "policy"): the name prefixes of networks and volumes.
func TestInvalidNodeStateFixtures(t *testing.T) {
	files, err := filepath.Glob("../../../protocol/fixtures/v1/invalid/node-state.*.json")
	if err != nil || len(files) == 0 {
		t.Fatalf("no fixtures: %v", err)
	}
	checked := 0
	for _, f := range files {
		data, err := os.ReadFile(f) //nolint:gosec // test fixture path
		if err != nil {
			t.Fatal(err)
		}
		var fx struct {
			EnforcedBy string          `json:"enforcedBy"`
			Problems   []string        `json:"problems"`
			Document   state.NodeState `json:"document"`
		}
		if err := stdjson.Unmarshal(data, &fx); err != nil {
			t.Fatalf("%s: %v", f, err)
		}
		if fx.EnforcedBy != "policy" {
			continue
		}
		checked++
		var got []string
		for i, n := range fx.Document.Networks {
			if CheckNetworkName(n.Name) != nil {
				got = append(got, fmt.Sprintf("networks[%d].name", i))
			}
		}
		for i, v := range fx.Document.Volumes {
			if CheckVolumeName(v.Name) != nil {
				got = append(got, fmt.Sprintf("volumes[%d].name", i))
			}
		}
		for i, v := range fx.Document.DeleteVolumes {
			if CheckVolumeName(v.Name) != nil {
				got = append(got, fmt.Sprintf("deleteVolumes[%d].name", i))
			}
		}
		for _, want := range fx.Problems {
			if !slices.Contains(got, want) {
				t.Errorf("%s: missing problem %s; got %v", filepath.Base(f), want, got)
			}
		}
	}
	if checked == 0 {
		t.Fatal("no policy fixtures")
	}
}
