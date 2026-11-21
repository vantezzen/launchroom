package state

import (
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestTeamMirrorRepository(t *testing.T) {
	cases := map[RegistryRepository]string{
		"mirror/team_01j9z8x7w6v5t4s3r2q1p0n9mk/ghcr.io/acme/api": "team_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		"mirror/docker.io/library/postgres":                       "",
		"mirror/team_01j9z8x7w6v5t4s3r2q1p0n9mk/acme/api":         "", // no registry host
		"mirror/team_81j9z8x7w6v5t4s3r2q1p0n9mk/ghcr.io/acme/api": "", // not a ULID
		"apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2":                     "",
	}
	for repo, want := range cases {
		team, ok := TeamMirrorRepository(repo)
		if team != want || ok != (want != "") {
			t.Errorf("%s: got %q %v, want %q", repo, team, ok, want)
		}
		if err := repo.Validate(); err != nil {
			t.Errorf("%s is not a registry repository: %v", repo, err)
		}
	}
}

func TestRuntimeContractRules(t *testing.T) {
	base := func() BuildSummary {
		return BuildSummary{Builder: BuilderRailpack, BuilderReason: ReasonRailpack}
	}
	cases := map[string]struct {
		edit func(*BuildSummary)
		bad  string
	}{
		"shared secret needs build scope": {func(s *BuildSummary) {
			s.GeneratedSecrets = []GeneratedSecret{{Name: "KEY", Length: 32, Encoding: "base64", Shared: ShareService}}
		}, "s.generatedSecrets[0].shared"},
		"duplicate alias": {func(s *BuildSummary) {
			s.EnvAliases = []EnvAlias{{Name: "APP_URL", From: AliasPublicURL}, {Name: "APP_URL", From: AliasDomain}}
		}, "s.envAliases.name[1]"},
		"unknown alias source": {func(s *BuildSummary) {
			s.EnvAliases = []EnvAlias{{Name: "APP_URL", From: "HOST"}}
		}, "s.envAliases[0].from"},
		"volume suggestion without reason": {func(s *BuildSummary) {
			s.VolumeSuggestions = []VolumeSuggestion{{Path: "/data"}}
		}, "s.volumeSuggestions[0].reason"},
		"composer package grammar": {func(s *BuildSummary) {
			s.PhpDependencyExtensions = []PhpDependencyExtension{{Name: "intl", For: "Filament"}}
		}, "s.phpDependencyExtensions[0].for"},
		"process stop timeout": {func(s *BuildSummary) {
			s.Processes = []ProcessSuggestion{{Name: "worker", Args: []string{"run"}, StopTimeout: "10m"}}
		}, "s.processes[0].stopTimeout"},
		"command without args": {func(s *BuildSummary) {
			s.Commands = []CommandSuggestion{{Name: "migrate", Label: "Run migrations"}}
		}, "s.commands[0].args"},
		"command without label": {func(s *BuildSummary) {
			s.Commands = []CommandSuggestion{{Name: "migrate", Args: []string{"migrate"}}}
		}, "s.commands[0].label"},
		"command label too long": {func(s *BuildSummary) {
			s.Commands = []CommandSuggestion{{Name: "migrate", Label: strings.Repeat("x", 65), Args: []string{"migrate"}}}
		}, "s.commands[0].label"},
		"command description too long": {func(s *BuildSummary) {
			s.Commands = []CommandSuggestion{{Name: "migrate", Label: "x", Description: strings.Repeat("x", 201), Args: []string{"migrate"}}}
		}, "s.commands[0].description"},
		"duplicate command": {func(s *BuildSummary) {
			c := CommandSuggestion{Name: "migrate", Label: "Run migrations", Args: []string{"migrate"}}
			s.Commands = []CommandSuggestion{c, c}
		}, "s.commands.name[1]"},
		"command name grammar": {func(s *BuildSummary) {
			s.Commands = []CommandSuggestion{{Name: "Migrate DB", Label: "x", Args: []string{"migrate"}}}
		}, "s.commands[0].name"},
		"too many commands": {func(s *BuildSummary) {
			for i := range 17 {
				s.Commands = append(s.Commands, CommandSuggestion{Name: Slug("c" + strconv.Itoa(i)), Label: "x", Args: []string{"x"}})
			}
		}, "s.commands"},
	}
	for name, c := range cases {
		s := base()
		var e errs
		s.validate(&e, "s")
		if err := e.err(); err != nil {
			t.Fatalf("base: %v", err)
		}
		c.edit(&s)
		s.validate(&e, "s")
		if err := e.err(); err == nil || !strings.Contains(err.Error(), c.bad) {
			t.Errorf("%s: want an error at %s, got %v", name, c.bad, err)
		}
	}
}

func TestEventOutputIsBoundedInBytes(t *testing.T) {
	at := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	ev := WorkloadEvent{Seq: 1, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7", At: at, Kind: EventStopped, Reason: ReasonDrained}
	for range MaxEventOutputLines {
		ev.Output = append(ev.Output, LogLine{Time: at, Stream: StreamStdout, Text: strings.Repeat("x", 200)})
	}
	var e errs
	ev.validate(&e, "ev")
	if err := e.err(); err == nil || !strings.Contains(err.Error(), "ev.output") {
		t.Fatalf("100 lines of 200 bytes passed: %v", err)
	}
	ev.Output = ev.Output[:80]
	e = errs{}
	ev.validate(&e, "ev")
	if err := e.err(); err != nil {
		t.Fatalf("16000 bytes refused: %v", err)
	}
}

func TestFallbackSecretByKind(t *testing.T) {
	if err := CheckFallbackSecret(FallbackSlack, map[string]string{"url": "https://hooks.slack.com/services/T/B/X"}); err != nil {
		t.Fatal(err)
	}
	if err := CheckFallbackSecret(FallbackEmail, map[string]string{"username": "u", "password": "p"}); err != nil {
		t.Fatal(err)
	}
	for kind, values := range map[FallbackKind]map[string]string{
		FallbackWebhook: {"url": "http://example.com/hook"},
		FallbackDiscord: {"url": "https://user:pw@discord.com/api/webhooks/1/x"},
		FallbackEmail:   {"username": "u"},
		FallbackSlack:   {"url": "https://hooks.slack.com/x", "password": "p"},
	} {
		if err := CheckFallbackSecret(kind, values); err == nil {
			t.Errorf("%s %v accepted", kind, values)
		} else if v := values["password"]; v != "" && strings.Contains(err.Error(), v+"\"") {
			t.Errorf("error leaks a value: %v", err)
		}
	}
}

func TestCertRetryAndCleanupSpecs(t *testing.T) {
	if err := (&CertRetrySpec{}).Validate(); err == nil {
		t.Fatal("no hosts accepted")
	}
	if err := (&CertRetrySpec{Hosts: []Hostname{"a.example.com", "a.example.com"}}).Validate(); err == nil {
		t.Fatal("duplicate hosts accepted")
	}
	if err := (&CleanupSpec{}).Validate(); err != nil {
		t.Fatal(err)
	}
}
