// Package mcp is a small Model Context Protocol server over stdio: JSON-RPC
// 2.0, one message per line (docs/16-api-and-cli.md §7.1). It knows tools,
// resources, prompts, progress, cancellation and the client's roots, and
// nothing about launchroom.
package mcp

import "encoding/json"

// ProtocolVersions are the protocol revisions this server speaks, newest
// first. The client's revision is answered when it is one of them, else
// the newest.
var ProtocolVersions = []string{"2025-11-25", "2025-06-18", "2025-03-26", "2024-11-05"}

// JSON-RPC error codes.
const (
	CodeParseError     = -32700
	CodeInvalidRequest = -32600
	CodeMethodNotFound = -32601
	CodeInvalidParams  = -32602
	CodeInternalError  = -32603
	// CodeResourceNotFound is MCP's code for an unknown resource URI.
	CodeResourceNotFound = -32002
)

// message is any JSON-RPC message: a request (ID and Method), a
// notification (Method only) or a response (ID and Result or Error).
type message struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id,omitempty"`
	Method  string          `json:"method,omitempty"`
	Params  json.RawMessage `json:"params,omitempty"`
	Result  json.RawMessage `json:"result,omitempty"`
	Error   *RPCError       `json:"error,omitempty"`
}

// RPCError is a JSON-RPC error object.
type RPCError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
	Data    any    `json:"data,omitempty"`
}

func (e *RPCError) Error() string { return e.Message }

// Annotations are hints about a tool's behaviour for the client (it may ask
// the person before a destructive tool runs).
type Annotations struct {
	Title           string `json:"title,omitempty"`
	ReadOnlyHint    bool   `json:"readOnlyHint"`
	DestructiveHint bool   `json:"destructiveHint"`
	IdempotentHint  bool   `json:"idempotentHint"`
	OpenWorldHint   bool   `json:"openWorldHint"`
}

// Content is one block of a tool result or prompt message (text only).
type Content struct {
	Type string `json:"type"`
	Text string `json:"text"`
}

// TextContent is a text block.
func TextContent(text string) Content { return Content{Type: "text", Text: text} }

// ToolResult is the answer of tools/call.
type ToolResult struct {
	Content           []Content `json:"content"`
	StructuredContent any       `json:"structuredContent,omitempty"`
	IsError           bool      `json:"isError,omitempty"`
}

// Resource is a concrete resource of resources/list.
type Resource struct {
	URI         string `json:"uri"`
	Name        string `json:"name"`
	Title       string `json:"title,omitempty"`
	Description string `json:"description,omitempty"`
	MimeType    string `json:"mimeType,omitempty"`
}

// ResourceTemplate is a parametrised resource of resources/templates/list.
type ResourceTemplate struct {
	URITemplate string `json:"uriTemplate"`
	Name        string `json:"name"`
	Title       string `json:"title,omitempty"`
	Description string `json:"description,omitempty"`
	MimeType    string `json:"mimeType,omitempty"`
}

// ResourceContents is one item of resources/read.
type ResourceContents struct {
	URI      string `json:"uri"`
	MimeType string `json:"mimeType,omitempty"`
	Text     string `json:"text"`
}

// PromptArgument is an argument of a prompt.
type PromptArgument struct {
	Name        string `json:"name"`
	Description string `json:"description,omitempty"`
	Required    bool   `json:"required,omitempty"`
}

// PromptMessage is one message of prompts/get.
type PromptMessage struct {
	Role    string  `json:"role"`
	Content Content `json:"content"`
}

// Root is a directory the client works in (roots/list).
type Root struct {
	URI  string `json:"uri"`
	Name string `json:"name,omitempty"`
}

type implementation struct {
	Name    string `json:"name"`
	Title   string `json:"title,omitempty"`
	Version string `json:"version"`
}

type initializeParams struct {
	ProtocolVersion string `json:"protocolVersion"`
	Capabilities    struct {
		Roots *struct{} `json:"roots"`
	} `json:"capabilities"`
	ClientInfo implementation `json:"clientInfo"`
}

type initializeResult struct {
	ProtocolVersion string         `json:"protocolVersion"`
	Capabilities    map[string]any `json:"capabilities"`
	ServerInfo      implementation `json:"serverInfo"`
	Instructions    string         `json:"instructions,omitempty"`
}

type toolDescriptor struct {
	Name         string      `json:"name"`
	Title        string      `json:"title,omitempty"`
	Description  string      `json:"description"`
	InputSchema  *Schema     `json:"inputSchema"`
	OutputSchema *Schema     `json:"outputSchema,omitempty"`
	Annotations  Annotations `json:"annotations"`
}

type callParams struct {
	Name      string          `json:"name"`
	Arguments json.RawMessage `json:"arguments"`
	Meta      struct {
		ProgressToken json.RawMessage `json:"progressToken"`
	} `json:"_meta"`
}

type promptDescriptor struct {
	Name        string           `json:"name"`
	Title       string           `json:"title,omitempty"`
	Description string           `json:"description,omitempty"`
	Arguments   []PromptArgument `json:"arguments,omitempty"`
}
