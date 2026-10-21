package state

import (
	"crypto/ed25519"
	"crypto/rand"
	"errors"
	"strings"
	"testing"
	"time"
)

func TestDeleteVolumesValidation(t *testing.T) {
	del := func() VolumeDeletion {
		return VolumeDeletion{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Name: "lr-v_0a1b2c3d4e5f6a7b", Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
			Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N6", RequestedAt: time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)}
	}
	ok := validNodeState(t)
	ok.DeleteVolumes = []VolumeDeletion{del()}
	if err := ok.Validate(); err != nil {
		t.Fatal(err)
	}
	cases := map[string]func(ns *NodeState){
		"still declared by id": func(ns *NodeState) {
			d := del()
			d.ID = ns.Volumes[0].ID
			ns.DeleteVolumes = []VolumeDeletion{d}
		},
		"still declared by name": func(ns *NodeState) {
			d := del()
			d.Name = ns.Volumes[0].Name
			ns.DeleteVolumes = []VolumeDeletion{d}
		},
		"duplicate":    func(ns *NodeState) { ns.DeleteVolumes = []VolumeDeletion{del(), del()} },
		"no time":      func(ns *NodeState) { d := del(); d.RequestedAt = time.Time{}; ns.DeleteVolumes = []VolumeDeletion{d} },
		"bad name":     func(ns *NodeState) { d := del(); d.Name = "data"; ns.DeleteVolumes = []VolumeDeletion{d} },
		"bad service":  func(ns *NodeState) { d := del(); d.Service = "svc_x"; ns.DeleteVolumes = []VolumeDeletion{d} },
		"no env label": func(ns *NodeState) { d := del(); d.Environment = ""; ns.DeleteVolumes = []VolumeDeletion{d} },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			ns := validNodeState(t)
			mutate(ns)
			if err := ns.Validate(); err == nil {
				t.Fatal("accepted")
			}
		})
	}
}

func TestReportRejectionAndVolumeDeletions(t *testing.T) {
	r := &Report{TypeMeta: NewTypeMeta(KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: time.Now(), Seq: 1, Workloads: []WorkloadStatus{},
		StateRejected:   &Rejection{Version: 7, Error: "invalid document: x", RejectedAt: time.Now()},
		VolumeDeletions: []VolumeDeletionResult{{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Name: "lr-v_a", Outcome: VolumeDeleted, At: time.Now()}}}
	if err := r.Validate(); err != nil {
		t.Fatal(err)
	}
	r.StateRejected.Error = strings.Repeat("x", 5000)
	r.VolumeDeletions = append(r.VolumeDeletions, r.VolumeDeletions[0])
	r.VolumeDeletions[1].Outcome = "gone"
	err := r.Validate()
	if err == nil || !strings.Contains(err.Error(), "stateRejected.error") || !strings.Contains(err.Error(), "volumeDeletions.id") || !strings.Contains(err.Error(), "outcome") {
		t.Fatalf("%v", err)
	}
}

// A correctly signed but invalid NodeState is a RejectedError carrying its
// version (reported to the dashboard); a bad signature is not.
func TestVerifyNodeStateReportsRejection(t *testing.T) {
	pub, key, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	server := ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	ns := validNodeState(t)
	ns.Version = 1844
	ns.Networks = append(ns.Networks, ns.Networks[0]) // the previews bug: a network twice
	raw, err := Encode(ns)
	if err != nil {
		t.Fatal(err)
	}
	_, err = VerifyNodeState(raw, sign(key, raw), keyVerifier{pub}, server)
	var rej *RejectedError
	if !errors.As(err, &rej) || rej.Version != 1844 || !strings.Contains(rej.Error(), "duplicate network id") {
		t.Fatalf("%#v", err)
	}
	_, otherKey, _ := ed25519.GenerateKey(rand.Reader)
	if _, err := VerifyNodeState(raw, sign(otherKey, raw), keyVerifier{pub}, server); err == nil || errors.As(err, &rej) {
		t.Fatalf("bad signature must not be a rejection report: %v", err)
	}
	if _, err := VerifyNodeState([]byte(`{"version": 9, "kind": "x"`), sign(key, []byte(`{"version": 9, "kind": "x"`)), keyVerifier{pub}, server); !errors.As(err, &rej) || rej.Version != 0 {
		t.Fatalf("unreadable document: %v", err)
	}
}
