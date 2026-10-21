package state

import (
	stdjson "encoding/json"
	"strings"
	"testing"
)

func TestNodeStateMeshValidation(t *testing.T) {
	base := readFixture(t, "node-state.mesh.json")
	ts := func(d map[string]any) map[string]any {
		return d["system"].(map[string]any)["tailscale"].(map[string]any)
	}
	reg := func(d map[string]any) map[string]any {
		return d["system"].(map[string]any)["registry"].(map[string]any)
	}
	cases := map[string]struct {
		mutate func(d map[string]any)
		want   string
	}{
		"auth key while disabled":        {func(d map[string]any) { ts(d)["enabled"] = false }, "authKey"},
		"login server with a path":       {func(d map[string]any) { ts(d)["loginServer"] = "https://dash.example.com/x" }, "loginServer"},
		"login server not http(s)":       {func(d map[string]any) { ts(d)["loginServer"] = "ftp://dash.example.com" }, "loginServer"},
		"enabled without login server":   {func(d map[string]any) { delete(ts(d), "loginServer") }, "loginServer"},
		"duplicate tags":                 {func(d map[string]any) { ts(d)["tags"] = []any{"tag:lr-edge", "tag:lr-edge"} }, "tags"},
		"bad tag":                        {func(d map[string]any) { ts(d)["tags"] = []any{"lr-edge"} }, "tags"},
		"serve on mesh with forwardTo":   {func(d map[string]any) { reg(d)["serveOnMesh"] = true }, "serveOnMesh"},
		"auth key not base64":            {func(d map[string]any) { ts(d)["authKey"] = map[string]any{"sealed": "not base64!"} }, "authKey"},
		"unknown field in tailscale":     {func(d map[string]any) { ts(d)["authKeyPlain"] = "x" }, "authKeyPlain"},
		"http login server (grammar ok)": {func(d map[string]any) { ts(d)["loginServer"] = "http://headscale" }, ""},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			var d map[string]any
			if err := stdjson.Unmarshal([]byte(base), &d); err != nil {
				t.Fatal(err)
			}
			c.mutate(d)
			raw, _ := stdjson.Marshal(d)
			_, err := Decode[NodeState](raw)
			if c.want == "" {
				if err != nil {
					t.Fatalf("unexpected error: %v", err)
				}
				return
			}
			if err == nil || !strings.Contains(err.Error(), c.want) {
				t.Fatalf("err = %v, want mention of %s", err, c.want)
			}
		})
	}
}

func TestReportMeshValidation(t *testing.T) {
	base := readFixture(t, "report.mesh.json")
	mesh := func(d map[string]any) map[string]any { return d["host"].(map[string]any)["mesh"].(map[string]any) }
	peer := func(d map[string]any) map[string]any { return mesh(d)["peers"].([]any)[0].(map[string]any) }
	cases := map[string]func(d map[string]any){
		"unknown state":       func(d map[string]any) { mesh(d)["state"] = "online" },
		"bad ip":              func(d map[string]any) { mesh(d)["ipv4"] = "100.64.0.256" },
		"non-canonical ip":    func(d map[string]any) { mesh(d)["ipv6"] = "FD7A:115C:A1E0::2" },
		"bad connection":      func(d map[string]any) { peer(d)["connection"] = "p2p" },
		"duplicate peer":      func(d map[string]any) { mesh(d)["peers"] = append(mesh(d)["peers"].([]any), peer(d)) },
		"missing checkedAt":   func(d map[string]any) { delete(mesh(d), "checkedAt") },
		"long message":        func(d map[string]any) { mesh(d)["message"] = strings.Repeat("x", 1025) },
		"relay label grammar": func(d map[string]any) { peer(d)["relay"] = "a b" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			var d map[string]any
			if err := stdjson.Unmarshal([]byte(base), &d); err != nil {
				t.Fatal(err)
			}
			mutate(d)
			raw, _ := stdjson.Marshal(d)
			if _, err := Decode[Report](raw); err == nil {
				t.Fatal("accepted")
			}
		})
	}
}
