// Package state defines the v1 daemon protocol documents (docs/03-daemon-protocol.md
// §5, §7): the signed NodeState, tasks with typed specs, enrollment, reports and
// log batches. These Go types are the source of truth for the JSON Schemas in
// protocol/schema/v1 (go generate), which the dashboard mirrors.
//
// Decoding is strict (unknown members, duplicate names, case-folded names and
// invalid UTF-8 are rejected) and every document has a Validate method that
// enforces the identifier grammars (docs/09-security.md R3). The package also
// persists the current NodeState with its signature (Store) and enforces
// monotonic versions.
package state
