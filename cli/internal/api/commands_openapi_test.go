package api

import "reflect"

// The one-off command models (D105), checked like the others in
// openapi_test.go (kept in their own file).
func init() {
	for typ, schema := range map[reflect.Type]string{
		reflect.TypeFor[Command]():                "CommandData",
		reflect.TypeFor[CommandCatalogue]():       "CommandCatalogueData",
		reflect.TypeFor[CommandRun]():             "CommandRunData",
		reflect.TypeFor[CommandOutputLine]():      "CommandOutputLineData",
		reflect.TypeFor[CommandLinesEvent]():      "CommandLinesEvent",
		reflect.TypeFor[CommandStatusEvent]():     "CommandStatusEvent",
		reflect.TypeFor[CommandEndEvent]():        "CommandEndEvent",
		reflect.TypeFor[StartCommandRunRequest](): "StartCommandRunInput",
	} {
		models[typ] = schema
	}
	streamEvents["/command-runs/{commandRun}/output/stream"] = map[string]reflect.Type{
		"lines":  reflect.TypeFor[CommandLinesEvent](),
		"status": reflect.TypeFor[CommandStatusEvent](),
		"end":    reflect.TypeFor[CommandEndEvent](),
	}
}
