package mcp

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

// maxMessage bounds one incoming line (a launchroom.json of 256 KiB fits
// many times over).
const maxMessage = 8 << 20

// Tool is one tool: its descriptor and its handler.
type Tool struct {
	Name         string
	Title        string
	Description  string
	Annotations  Annotations
	InputSchema  *Schema
	OutputSchema *Schema
	// Handle runs the tool on validated arguments.
	Handle func(ctx context.Context, req *Request, args json.RawMessage) (*ToolResult, error)
}

// ToolError is an error a tool reports to the model (isError result)
// instead of a JSON-RPC error.
type ToolError struct{ Message string }

func (e *ToolError) Error() string { return e.Message }

// Errorf returns a ToolError.
func Errorf(format string, args ...any) error {
	return &ToolError{Message: fmt.Sprintf(format, args...)}
}

// NewTool builds a tool from a typed handler: In is decoded from the
// validated arguments (unknown fields refused), Out is the structured
// result and the string its compact text. Any error becomes an isError
// result with the error's text.
func NewTool[In, Out any](name, title, description string, ann Annotations, handle func(ctx context.Context, req *Request, in In) (Out, string, error)) Tool {
	var in In
	var out Out
	ann.Title = title
	return Tool{
		Name:         name,
		Title:        title,
		Description:  description,
		Annotations:  ann,
		InputSchema:  SchemaFor(in),
		OutputSchema: SchemaFor(out),
		Handle: func(ctx context.Context, req *Request, raw json.RawMessage) (*ToolResult, error) {
			var args In
			dec := json.NewDecoder(bytes.NewReader(raw))
			dec.DisallowUnknownFields()
			if err := dec.Decode(&args); err != nil {
				return nil, &RPCError{Code: CodeInvalidParams, Message: "arguments: " + err.Error()}
			}
			result, text, err := handle(ctx, req, args)
			if err != nil {
				return nil, err
			}
			return &ToolResult{Content: []Content{TextContent(text)}, StructuredContent: result}, nil
		},
	}
}

// Prompt is one prompt template.
type Prompt struct {
	Name        string
	Title       string
	Description string
	Arguments   []PromptArgument
	// Get renders the messages for the given arguments.
	Get func(ctx context.Context, args map[string]string) (description string, messages []PromptMessage, err error)
}

// Resources serves resources/list, resources/templates/list and
// resources/read.
type Resources interface {
	List(ctx context.Context) ([]Resource, error)
	Templates() []ResourceTemplate
	// Read answers the contents of uri; an unknown URI is *RPCError with
	// CodeResourceNotFound.
	Read(ctx context.Context, uri string) ([]ResourceContents, error)
}

// Server answers one client over a reader and a writer.
type Server struct {
	Name, Title, Version string
	Instructions         string
	// Tools lists the tools the client may see now (called per tools/list
	// and tools/call, so it can depend on the token's abilities).
	Tools     func(ctx context.Context) []Tool
	Resources Resources
	Prompts   []Prompt
	// Log receives diagnostics (stderr); nil discards them.
	Log io.Writer

	out      io.Writer
	writeMu  sync.Mutex
	mu       sync.Mutex
	inflight map[string]*inflight
	pending  map[string]chan *message
	nextID   atomic.Int64
	roots    atomic.Bool // the client offers roots/list
	wg       sync.WaitGroup
}

type inflight struct {
	cancel    context.CancelFunc
	cancelled atomic.Bool
}

// Request is the context of one tools/call: progress and the client's
// roots.
type Request struct {
	srv      *Server
	token    json.RawMessage
	progress atomic.Int64
}

// Progress sends notifications/progress with message when the client asked
// for progress (a progressToken); progress counts up by one per call.
func (r *Request) Progress(message string) {
	if r == nil || len(r.token) == 0 {
		return
	}
	n := r.progress.Add(1)
	r.srv.notify("notifications/progress", map[string]any{
		"progressToken": r.token,
		"progress":      n,
		"message":       message,
	})
}

// Roots asks the client for its roots; nil when it doesn't offer any.
func (r *Request) Roots(ctx context.Context) ([]Root, error) {
	if r == nil {
		return nil, nil
	}
	return r.srv.Roots(ctx)
}

// Roots asks the client for its roots (roots/list); nil when the client
// doesn't offer them.
func (s *Server) Roots(ctx context.Context) ([]Root, error) {
	if !s.roots.Load() {
		return nil, nil
	}
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	raw, err := s.call(ctx, "roots/list", map[string]any{})
	if err != nil {
		return nil, err
	}
	var res struct {
		Roots []Root `json:"roots"`
	}
	if err := json.Unmarshal(raw, &res); err != nil {
		return nil, err
	}
	return res.Roots, nil
}

// Serve reads messages from in until EOF or ctx ends and answers on out.
// Requests run concurrently; Serve returns after the running ones ended.
func (s *Server) Serve(ctx context.Context, in io.Reader, out io.Writer) error {
	s.out = out
	s.inflight = map[string]*inflight{}
	s.pending = map[string]chan *message{}
	ctx, cancel := context.WithCancel(ctx)
	defer func() {
		cancel()
		s.wg.Wait()
	}()

	lines := make(chan []byte)
	readErr := make(chan error, 1)
	go func() {
		sc := bufio.NewScanner(in)
		sc.Buffer(make([]byte, 64<<10), maxMessage)
		for sc.Scan() {
			line := append([]byte(nil), sc.Bytes()...)
			select {
			case lines <- line:
			case <-ctx.Done():
				return
			}
		}
		readErr <- sc.Err()
	}()
	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case err := <-readErr:
			if errors.Is(err, bufio.ErrTooLong) {
				s.writeError(nil, CodeParseError, "message larger than 8 MiB")
			}
			return err
		case line := <-lines:
			s.dispatch(ctx, line)
		}
	}
}

func (s *Server) dispatch(ctx context.Context, line []byte) {
	line = bytes.TrimSpace(line)
	if len(line) == 0 {
		return
	}
	if line[0] == '[' {
		s.writeError(nil, CodeInvalidRequest, "batches are not supported")
		return
	}
	var m message
	if err := json.Unmarshal(line, &m); err != nil {
		s.writeError(nil, CodeParseError, "not JSON: "+err.Error())
		return
	}
	if m.JSONRPC != "2.0" {
		s.writeError(m.ID, CodeInvalidRequest, `jsonrpc must be "2.0"`)
		return
	}
	switch {
	case m.Method == "" && len(m.ID) > 0:
		s.mu.Lock()
		ch := s.pending[string(m.ID)]
		delete(s.pending, string(m.ID))
		s.mu.Unlock()
		if ch != nil {
			ch <- &m
		}
	case len(m.ID) == 0:
		s.notification(m)
	default:
		s.request(ctx, m)
	}
}

func (s *Server) notification(m message) {
	switch m.Method {
	case "notifications/cancelled":
		var p struct {
			RequestID json.RawMessage `json:"requestId"`
		}
		if json.Unmarshal(m.Params, &p) == nil {
			s.mu.Lock()
			if f := s.inflight[string(p.RequestID)]; f != nil {
				f.cancelled.Store(true)
				f.cancel()
			}
			s.mu.Unlock()
		}
	case "notifications/initialized", "notifications/roots/list_changed":
	}
}

func (s *Server) request(ctx context.Context, m message) {
	if m.Method == "initialize" {
		// Answered inline so that the capabilities are known before any
		// other request runs.
		res, err := s.initialize(m.Params)
		s.answer(m.ID, res, err)
		return
	}
	ctx, cancel := context.WithCancel(ctx)
	f := &inflight{cancel: cancel}
	key := string(m.ID)
	s.mu.Lock()
	s.inflight[key] = f
	s.mu.Unlock()
	s.wg.Add(1)
	go func() {
		defer s.wg.Done()
		defer func() {
			cancel()
			s.mu.Lock()
			delete(s.inflight, key)
			s.mu.Unlock()
		}()
		res, err := s.handle(ctx, m)
		if f.cancelled.Load() {
			return // the client no longer waits for an answer
		}
		s.answer(m.ID, res, err)
	}()
}

func (s *Server) handle(ctx context.Context, m message) (res any, err error) {
	defer func() {
		if r := recover(); r != nil {
			s.logf("panic in %s: %v", m.Method, r)
			res, err = nil, &RPCError{Code: CodeInternalError, Message: "internal error"}
		}
	}()
	switch m.Method {
	case "ping":
		return map[string]any{}, nil
	case "tools/list":
		tools := s.tools(ctx)
		list := make([]toolDescriptor, len(tools))
		for i, t := range tools {
			list[i] = toolDescriptor{t.Name, t.Title, t.Description, t.InputSchema, t.OutputSchema, t.Annotations}
		}
		return map[string]any{"tools": list}, nil
	case "tools/call":
		return s.callTool(ctx, m.Params)
	case "resources/list":
		if s.Resources == nil {
			return map[string]any{"resources": []Resource{}}, nil
		}
		list, err := s.Resources.List(ctx)
		if err != nil {
			return nil, err
		}
		return map[string]any{"resources": list}, nil
	case "resources/templates/list":
		templates := []ResourceTemplate{}
		if s.Resources != nil {
			templates = s.Resources.Templates()
		}
		return map[string]any{"resourceTemplates": templates}, nil
	case "resources/read":
		var p struct {
			URI string `json:"uri"`
		}
		if err := json.Unmarshal(m.Params, &p); err != nil || p.URI == "" {
			return nil, &RPCError{Code: CodeInvalidParams, Message: "uri is required"}
		}
		if s.Resources == nil {
			return nil, &RPCError{Code: CodeResourceNotFound, Message: "no resource " + p.URI}
		}
		contents, err := s.Resources.Read(ctx, p.URI)
		if err != nil {
			return nil, err
		}
		return map[string]any{"contents": contents}, nil
	case "prompts/list":
		list := make([]promptDescriptor, len(s.Prompts))
		for i, p := range s.Prompts {
			list[i] = promptDescriptor{p.Name, p.Title, p.Description, p.Arguments}
		}
		return map[string]any{"prompts": list}, nil
	case "prompts/get":
		return s.getPrompt(ctx, m.Params)
	case "logging/setLevel", "completion/complete":
		return nil, &RPCError{Code: CodeMethodNotFound, Message: m.Method + " is not supported"}
	}
	return nil, &RPCError{Code: CodeMethodNotFound, Message: "unknown method " + m.Method}
}

func (s *Server) initialize(params json.RawMessage) (any, error) {
	var p initializeParams
	if err := json.Unmarshal(params, &p); err != nil {
		return nil, &RPCError{Code: CodeInvalidParams, Message: "initialize: " + err.Error()}
	}
	s.roots.Store(p.Capabilities.Roots != nil)
	version := ProtocolVersions[0]
	for _, v := range ProtocolVersions {
		if v == p.ProtocolVersion {
			version = v
		}
	}
	caps := map[string]any{"tools": map[string]any{"listChanged": false}}
	if s.Resources != nil {
		caps["resources"] = map[string]any{"listChanged": false, "subscribe": false}
	}
	if len(s.Prompts) > 0 {
		caps["prompts"] = map[string]any{"listChanged": false}
	}
	return initializeResult{
		ProtocolVersion: version,
		Capabilities:    caps,
		ServerInfo:      implementation{Name: s.Name, Title: s.Title, Version: s.Version},
		Instructions:    s.Instructions,
	}, nil
}

func (s *Server) tools(ctx context.Context) []Tool {
	if s.Tools == nil {
		return nil
	}
	return s.Tools(ctx)
}

func (s *Server) callTool(ctx context.Context, params json.RawMessage) (any, error) {
	var p callParams
	if err := json.Unmarshal(params, &p); err != nil {
		return nil, &RPCError{Code: CodeInvalidParams, Message: "tools/call: " + err.Error()}
	}
	var tool *Tool
	for _, t := range s.tools(ctx) {
		if t.Name == p.Name {
			tool = &t
			break
		}
	}
	if tool == nil {
		return nil, &RPCError{Code: CodeInvalidParams, Message: fmt.Sprintf("unknown tool %q", p.Name)}
	}
	args := p.Arguments
	if len(bytes.TrimSpace(args)) == 0 || string(bytes.TrimSpace(args)) == "null" {
		args = json.RawMessage("{}")
	}
	value, err := decodeJSON(args)
	if err != nil {
		return errorResult("The arguments are not valid JSON: " + err.Error()), nil
	}
	if err := tool.InputSchema.Validate(value); err != nil {
		return errorResult("Invalid arguments: " + err.Error()), nil
	}
	req := &Request{srv: s, token: p.Meta.ProgressToken}
	if string(req.token) == "null" {
		req.token = nil
	}
	res, err := tool.Handle(ctx, req, args)
	if err != nil {
		var rpc *RPCError
		if errors.As(err, &rpc) && rpc.Code == CodeInvalidParams {
			return errorResult("Invalid arguments: " + rpc.Message), nil
		}
		if ctx.Err() != nil {
			return errorResult("Cancelled."), nil
		}
		return errorResult(err.Error()), nil
	}
	return res, nil
}

func errorResult(text string) *ToolResult {
	return &ToolResult{Content: []Content{TextContent(text)}, IsError: true}
}

func (s *Server) getPrompt(ctx context.Context, params json.RawMessage) (any, error) {
	var p struct {
		Name      string            `json:"name"`
		Arguments map[string]string `json:"arguments"`
	}
	if err := json.Unmarshal(params, &p); err != nil {
		return nil, &RPCError{Code: CodeInvalidParams, Message: "prompts/get: " + err.Error()}
	}
	for _, pr := range s.Prompts {
		if pr.Name != p.Name {
			continue
		}
		for _, a := range pr.Arguments {
			if a.Required && p.Arguments[a.Name] == "" {
				return nil, &RPCError{Code: CodeInvalidParams, Message: fmt.Sprintf("prompt %s needs the argument %q", pr.Name, a.Name)}
			}
		}
		desc, messages, err := pr.Get(ctx, p.Arguments)
		if err != nil {
			return nil, &RPCError{Code: CodeInvalidParams, Message: err.Error()}
		}
		return map[string]any{"description": desc, "messages": messages}, nil
	}
	return nil, &RPCError{Code: CodeInvalidParams, Message: fmt.Sprintf("unknown prompt %q", p.Name)}
}

// call sends a request to the client and waits for its answer.
func (s *Server) call(ctx context.Context, method string, params any) (json.RawMessage, error) {
	id := "lr-" + strconv.FormatInt(s.nextID.Add(1), 10)
	rawID, _ := json.Marshal(id)
	ch := make(chan *message, 1)
	s.mu.Lock()
	s.pending[string(rawID)] = ch
	s.mu.Unlock()
	p, err := json.Marshal(params)
	if err != nil {
		return nil, err
	}
	s.write(message{JSONRPC: "2.0", ID: rawID, Method: method, Params: p})
	select {
	case <-ctx.Done():
		s.mu.Lock()
		delete(s.pending, string(rawID))
		s.mu.Unlock()
		return nil, ctx.Err()
	case m := <-ch:
		if m.Error != nil {
			return nil, m.Error
		}
		return m.Result, nil
	}
}

func (s *Server) notify(method string, params any) {
	p, err := json.Marshal(params)
	if err != nil {
		return
	}
	s.write(message{JSONRPC: "2.0", Method: method, Params: p})
}

func (s *Server) answer(id json.RawMessage, res any, err error) {
	if err != nil {
		var rpc *RPCError
		if errors.As(err, &rpc) {
			s.write(message{JSONRPC: "2.0", ID: id, Error: rpc})
			return
		}
		s.logf("request failed: %v", err)
		s.write(message{JSONRPC: "2.0", ID: id, Error: &RPCError{Code: CodeInternalError, Message: err.Error()}})
		return
	}
	raw, mErr := json.Marshal(res)
	if mErr != nil {
		s.write(message{JSONRPC: "2.0", ID: id, Error: &RPCError{Code: CodeInternalError, Message: "encoding the result failed"}})
		return
	}
	s.write(message{JSONRPC: "2.0", ID: id, Result: raw})
}

func (s *Server) writeError(id json.RawMessage, code int, msg string) {
	if len(id) == 0 {
		id = json.RawMessage("null")
	}
	s.write(message{JSONRPC: "2.0", ID: id, Error: &RPCError{Code: code, Message: msg}})
}

func (s *Server) write(m message) {
	b, err := json.Marshal(m)
	if err != nil {
		s.logf("encoding a message failed: %v", err)
		return
	}
	s.writeMu.Lock()
	defer s.writeMu.Unlock()
	if _, err := s.out.Write(append(b, '\n')); err != nil {
		s.logf("writing failed: %v", err)
	}
}

func (s *Server) logf(format string, args ...any) {
	if s.Log != nil {
		_, _ = fmt.Fprintf(s.Log, "launchroom mcp: "+format+"\n", args...)
	}
}
