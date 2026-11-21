package api

import "reflect"

// The failure report, fix, ray and preview models (D111), checked like the
// others in openapi_test.go.
func init() {
	for typ, schema := range map[reflect.Type]string{
		reflect.TypeFor[FailureExplanation](): "ApiFailureExplanationData",
		reflect.TypeFor[FailureHint]():        "ApiFailureHintData",
		reflect.TypeFor[ExcerptLine]():        "ApiLogExcerptLineData",
		reflect.TypeFor[FailureFix]():         "ApiFailureFixData",
		reflect.TypeFor[FailureReport]():      "ApiFailureReportData",
		reflect.TypeFor[FixRequest]():         "ApplyFailureFixInput",
		reflect.TypeFor[FixResult]():          "ApiFailureFixResultData",
		reflect.TypeFor[TraceServer]():        "ApiTraceServerData",
		reflect.TypeFor[WafRule]():            "WafRuleData",
		reflect.TypeFor[TraceFirewall]():      "TraceFirewallData",
		reflect.TypeFor[TraceService]():       "SecurityServiceRefData",
		reflect.TypeFor[Trace]():              "ApiTraceData",
		reflect.TypeFor[PreviewURL]():         "PreviewUrlData",
		reflect.TypeFor[Preview]():            "PreviewResource",
		reflect.TypeFor[ConfigStatus]():       "ConfigFileEnvironmentData",
		reflect.TypeFor[DeploymentChecks]():   "DeploymentChecksData",
		reflect.TypeFor[CommitCheck]():        "CommitCheckData",
	} {
		models[typ] = schema
	}
}
