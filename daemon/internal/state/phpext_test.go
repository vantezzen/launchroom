package state

import (
	"strings"
	"testing"
)

// docs/04 §3.1: builder.phpExtensions is an allow-list with an engine each.
func TestPhpExtensionsSpec(t *testing.T) {
	spec := func(xs ...PhpExtension) *PlanSpec {
		return &PlanSpec{BuildInputs: BuildInputs{
			Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Repo:     RepoSpec{Provider: GitHub, CloneURL: "https://github.com/acme/web.git", SHA: GitSHA(strings.Repeat("a", 40))},
			Builder:  BuilderSpec{Kind: BuilderAuto, RailpackVersion: "0.42.0", PhpExtensions: xs},
			Platform: "linux/amd64", CacheKey: "svc_01j9z8x7w6v5t4s3r2q1p0n9mk",
		}}
	}
	ok := []PhpExtension{{Name: PhpExtPdoPgsql, For: EnginePostgres}, {Name: PhpExtRedis, For: EngineValkey}, {Name: PhpExtMongoDB, For: EngineMongoDB}}
	if err := spec(ok...).Validate(); err != nil {
		t.Fatalf("valid extensions: %v", err)
	}
	// Every builder kind may carry them: Dockerfile builds only warn.
	df := spec(ok...)
	df.Builder = BuilderSpec{Kind: BuilderDockerfile, PhpExtensions: ok}
	if err := df.Validate(); err != nil {
		t.Fatalf("dockerfile build: %v", err)
	}
	for name, xs := range map[string][]PhpExtension{
		"not allow-listed": {{Name: "xdebug", For: EnginePostgres}},
		"separator":        {{Name: "pdo_pgsql,gd", For: EnginePostgres}},
		"shell":            {{Name: "pdo_pgsql; id", For: EnginePostgres}},
		"no engine":        {{Name: PhpExtPdoPgsql}},
		"unknown engine":   {{Name: PhpExtPdoPgsql, For: "sqlite"}},
		"duplicate":        {{Name: PhpExtRedis, For: EngineRedis}, {Name: PhpExtRedis, For: EngineValkey}},
		"too many": {
			{Name: PhpExtPdoPgsql, For: EnginePostgres}, {Name: PhpExtPgsql, For: EnginePostgres}, {Name: PhpExtPdoMysql, For: EngineMySQL},
			{Name: PhpExtMysqli, For: EngineMySQL}, {Name: PhpExtRedis, For: EngineRedis}, {Name: PhpExtMongoDB, For: EngineMongoDB},
			{Name: PhpExtPdoPgsql, For: EnginePostgres}, {Name: PhpExtPgsql, For: EnginePostgres}, {Name: PhpExtRedis, For: EngineRedis},
		},
	} {
		if err := spec(xs...).Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}
