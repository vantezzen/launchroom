package state

import (
	"encoding/json/jsontext"
	"encoding/json/v2"
	"fmt"
)

// MaxDocumentBytes bounds any protocol document the daemon decodes.
const MaxDocumentBytes = 16 << 20

// strictOptions are the decoding options for every protocol document:
// unknown members, duplicate names and invalid UTF-8 are errors, and member
// names match case-sensitively (encoding/json/v2 default).
func strictOptions() json.Options {
	return json.JoinOptions(
		json.RejectUnknownMembers(true),
		jsontext.AllowDuplicateNames(false),
		jsontext.AllowInvalidUTF8(false),
	)
}

// DecodeStrict decodes exactly one JSON value from data into v with the strict options.
func DecodeStrict(data []byte, v any) error {
	if len(data) > MaxDocumentBytes {
		return fmt.Errorf("state: document is %d bytes, limit is %d", len(data), MaxDocumentBytes)
	}
	if err := json.Unmarshal(data, v, strictOptions()); err != nil {
		return fmt.Errorf("state: decode: %w", err)
	}
	return nil
}

// Document is implemented by every validatable protocol document.
type Document interface {
	Validate() error
}

// Decode strictly decodes and validates a document of type T.
func Decode[T any, PT interface {
	*T
	Document
}](data []byte) (*T, error) {
	v := new(T)
	if err := DecodeStrict(data, v); err != nil {
		return nil, err
	}
	if err := PT(v).Validate(); err != nil {
		return nil, err
	}
	return v, nil
}

// DecodeNodeState strictly decodes and validates a NodeState.
func DecodeNodeState(data []byte) (*NodeState, error) { return Decode[NodeState](data) }

// Encode marshals a protocol document (compact, deterministic map order).
func Encode(v any) ([]byte, error) {
	return json.Marshal(v, json.Deterministic(true))
}
