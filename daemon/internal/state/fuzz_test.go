package state

import (
	"os"
	"path/filepath"
	"testing"
)

func FuzzDecodeNodeState(f *testing.F) {
	seed, err := os.ReadFile(filepath.Join(fixtureDir, "node-state.web-postgres.json"))
	if err != nil {
		f.Fatal(err)
	}
	f.Add(seed)
	f.Add([]byte(`{}`))
	f.Add([]byte(`{"apiVersion":"launchroom.vantezzen.io/v1","kind":"NodeState","workloads":[{"args":[]}]}`))
	f.Add([]byte(`null`))

	f.Fuzz(func(t *testing.T, data []byte) {
		ns, err := DecodeNodeState(data)
		if err != nil {
			return
		}
		// Anything accepted must re-encode and be accepted again identically.
		out, err := Encode(ns)
		if err != nil {
			t.Fatalf("accepted document does not re-encode: %v", err)
		}
		if _, err := DecodeNodeState(out); err != nil {
			t.Fatalf("re-encoded document rejected: %v", err)
		}
	})
}

func FuzzDecodeTaskLease(f *testing.F) {
	seed, err := os.ReadFile(filepath.Join(fixtureDir, "task-lease.build.json"))
	if err != nil {
		f.Fatal(err)
	}
	f.Add(seed)
	f.Add([]byte(`{"apiVersion":"launchroom.vantezzen.io/v1","kind":"TaskLease","tasks":[{"type":"logs","spec":{}}]}`))
	f.Fuzz(func(t *testing.T, data []byte) {
		l, err := Decode[TaskLease](data)
		if err != nil {
			return
		}
		for _, task := range l.Tasks {
			if task.Spec == nil || task.Spec.TaskType() != task.Type {
				t.Fatalf("accepted task with mismatched spec: %+v", task)
			}
		}
	})
}
