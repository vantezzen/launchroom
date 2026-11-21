package api

import "reflect"

// The app update models (D106), checked like the others in openapi_test.go.
func init() {
	for typ, schema := range map[reflect.Type]string{
		reflect.TypeFor[UpdateCandidate]():       "UpdateCandidateData",
		reflect.TypeFor[AppUpdateRun]():          "AppUpdateRunData",
		reflect.TypeFor[AppUpdate]():             "AppUpdateResource",
		reflect.TypeFor[StartAppUpdateRequest](): "StartAppUpdateData",
	} {
		models[typ] = schema
	}
}
