package api

import "reflect"

// The models `launchroom mcp` reads (docs/16 §7), checked like the others
// in openapi_test.go.
func init() {
	for typ, schema := range map[reflect.Type]string{
		reflect.TypeFor[TokenScope]():      "ApiTokenScopeData",
		reflect.TypeFor[AnalyticsTotals](): "AnalyticsTotalsData",
		reflect.TypeFor[AnalyticsPath]():   "AnalyticsPathData",
		reflect.TypeFor[AnalyticsSplit]():  "AnalyticsSplitData",
		reflect.TypeFor[DeployMarker]():    "DeployMarkerData",
		reflect.TypeFor[Analytics]():       "AnalyticsData",
	} {
		models[typ] = schema
	}
}
