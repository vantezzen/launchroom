package state

import (
	"regexp"
	"slices"
	"testing"
)

func TestVocabulary(t *testing.T) {
	v := ProtocolVocabulary()
	for name, src := range v.Grammars {
		if _, err := regexp.Compile(src); err != nil {
			t.Errorf("grammar %s: %v", name, err)
		}
	}
	for path, want := range map[string]DaemonFeature{
		"NodeState.volumes[].service":        FeatureSharedVolumes,
		"NodeState.workloads[].schedule":     FeatureCronSchedule,
		"NodeState.routes[].waf":             FeatureWaf,
		"Task(build).spec.repo.ref":          FeatureGitProviders,
		"Task(run_once).spec.probe":          FeatureGeneratedDockerfile,
		"Task(plan).spec.builder.dockerfile": FeatureGeneratedDockerfile,
	} {
		if got := v.FieldFeatures[path]; got != want {
			t.Errorf("fieldFeatures[%s] = %q, want %q", path, got, want)
		}
	}
	for _, f := range v.FieldFeatures {
		if !slices.Contains(v.Features, f) {
			t.Errorf("field feature %q is not a known feature", f)
		}
	}
	for _, f := range SupportedFeatures {
		if !slices.Contains(v.Features, f) {
			t.Errorf("supported feature %q missing from AllFeatures", f)
		}
	}
	if !slices.Contains(v.ForbiddenMountTargets, "/var/run/docker.sock") {
		t.Error("forbidden mount targets")
	}
}
