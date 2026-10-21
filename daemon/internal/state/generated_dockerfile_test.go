package state

import (
	"encoding/base64"
	"strings"
	"testing"
)

// docs/20 §6: builder.dockerfile, plan inspect, run_once probe.
func TestGeneratedDockerfileSpecs(t *testing.T) {
	sealed := Sealed{Sealed: SealedBox(base64.StdEncoding.EncodeToString(make([]byte, 64)))}
	inputs := func(b BuilderSpec) BuildInputs {
		return BuildInputs{
			Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Repo:    RepoSpec{Provider: GitHub, CloneURL: "https://github.com/acme/web.git", SHA: GitSHA(strings.Repeat("a", 40))},
			Builder: b, Platform: "linux/amd64", CacheKey: "svc_01j9z8x7w6v5t4s3r2q1p0n9mk",
		}
	}
	df := "FROM busybox:1.37\nCMD [\"true\"]\n"
	if err := (&PlanSpec{BuildInputs: inputs(BuilderSpec{Kind: BuilderDockerfile, Dockerfile: df}), Inspect: true}).Validate(); err != nil {
		t.Fatalf("inline dockerfile plan: %v", err)
	}
	for name, b := range map[string]BuilderSpec{
		"with railpack": {Kind: BuilderRailpack, RailpackVersion: "0.42.0", Dockerfile: df},
		"with auto":     {Kind: BuilderAuto, RailpackVersion: "0.42.0", Dockerfile: df},
		"with path":     {Kind: BuilderDockerfile, DockerfilePath: "Dockerfile", Dockerfile: df},
		"nul":           {Kind: BuilderDockerfile, Dockerfile: "FROM x\x00"},
		"invalid utf8":  {Kind: BuilderDockerfile, Dockerfile: "FROM \xff"},
		"too large":     {Kind: BuilderDockerfile, Dockerfile: strings.Repeat("a", 65537)},
	} {
		if err := (&PlanSpec{BuildInputs: inputs(b)}).Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}

	img := ImageRef("127.0.0.1:5000/apps/x@sha256:" + strings.Repeat("a", 64))
	run := func(p *RunOnceProbe) *RunOnceSpec {
		return &RunOnceSpec{Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Image: img, Env: sealed, Network: NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Name: "lr-e_01j9z8x7w6v5t4s3r2q1p0n9mk", Subnet: "10.231.4.0/24"},
			Timeout: "3m", Resources: Resources{CPUs: 1, MemoryMB: 256, Pids: 128}, Probe: p}
	}
	if err := run(&RunOnceProbe{Port: 8080, Path: "/health", Within: "90s"}).Validate(); err != nil {
		t.Fatalf("probe without argv: %v", err)
	}
	for name, p := range map[string]*RunOnceProbe{
		"no probe, no argv": nil,
		"within too long":   {Port: 8080, Within: "11m"},
		"within too short":  {Port: 8080, Within: "500ms"},
		"port 0":            {Port: 0, Within: "90s"},
	} {
		if err := run(p).Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}

	completion := func(ins *RepoInspection, probe *ProbeOutcome) *TaskCompletion {
		c := &TaskCompletion{TypeMeta: NewTypeMeta(KindTaskCompletion), Attempt: 1, Outcome: OutcomeSucceeded, Inspection: ins}
		if probe != nil {
			c.RunOnce = &RunOnceResult{ExitCode: 0, DurationMs: 10, Probe: probe}
		}
		return c
	}
	if err := completion(&RepoInspection{Tree: []RelPath{"package.json"}, Files: []InspectedFile{{Path: "package.json", Content: "{}"}}}, &ProbeOutcome{Listening: true, AfterMs: 1200, HTTPStatus: 200}).Validate(); err != nil {
		t.Fatalf("valid completion: %v", err)
	}
	big := strings.Repeat("a", MaxInspectedFileBytes)
	files := make([]InspectedFile, 9)
	for i := range files {
		files[i] = InspectedFile{Path: RelPath("f" + string(rune('a'+i))), Content: big}
	}
	for name, c := range map[string]*TaskCompletion{
		"total too large": completion(&RepoInspection{Files: files}, nil),
		"file too large":  completion(&RepoInspection{Files: []InspectedFile{{Path: "a", Content: big + "x"}}}, nil),
		"bad path":        completion(&RepoInspection{Tree: []RelPath{"../etc/passwd"}}, nil),
		"bad status":      completion(nil, &ProbeOutcome{HTTPStatus: 99}),
		"too many ports":  completion(nil, &ProbeOutcome{ListeningPorts: make([]Port, 17)}),
	} {
		if err := c.Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}
