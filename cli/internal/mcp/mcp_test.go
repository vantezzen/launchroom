package mcp

import (
	"bufio"
	"context"
	"encoding/json"
	"io"
	"strings"
	"sync"
	"testing"
	"time"
)

type echoIn struct {
	Text  string   `json:"text" maxLength:"10" desc:"What to echo."`
	Times int      `json:"times,omitempty" min:"1" max:"3"`
	Mode  string   `json:"mode,omitempty" enum:"loud,quiet"`
	ID    string   `json:"id,omitempty" pattern:"^dpl_[0-9]+$"`
	Tags  []string `json:"tags,omitempty" maxItems:"2"`
}

type echoOut struct {
	Text  string   `json:"text"`
	Lines []string `json:"lines"`
	Note  *string  `json:"note"`
}

func TestSchemaForStructs(t *testing.T) {
	s := SchemaFor(echoIn{})
	b, _ := json.Marshal(s)
	got := string(b)
	for _, want := range []string{
		`"type":"object"`, `"required":["text"]`, `"additionalProperties":false`,
		`"maxLength":10`, `"description":"What to echo."`, `"enum":["loud","quiet"]`,
		`"minimum":1`, `"maximum":3`, `"pattern":"^dpl_[0-9]+$"`, `"maxItems":2`,
	} {
		if !strings.Contains(got, want) {
			t.Errorf("schema lacks %s:\n%s", want, got)
		}
	}
	out := SchemaFor(echoOut{})
	if !strings.Contains(mustJSON(t, out.Properties["lines"]), `["array","null"]`) {
		t.Errorf("a slice without omitempty may be null: %s", mustJSON(t, out.Properties["lines"]))
	}
	if !strings.Contains(mustJSON(t, out.Properties["note"]), `["string","null"]`) {
		t.Errorf("a pointer may be null")
	}
	if strings.Join(out.Required, ",") != "lines,text" {
		t.Errorf("required = %v", out.Required)
	}
}

func TestValidate(t *testing.T) {
	s := SchemaFor(echoIn{})
	cases := map[string]string{
		`{"text":"hi"}`:                     "",
		`{}`:                                `missing "text"`,
		`{"text":"hi","nope":1}`:            `unknown "nope"`,
		`{"text":5}`:                        "text: must be string",
		`{"text":"01234567890"}`:            "at most 10 characters",
		`{"text":"a","times":4}`:            "times: must be at most 3",
		`{"text":"a","times":1.5}`:          "times: must be integer",
		`{"text":"a","mode":"shout"}`:       "must be one of loud, quiet",
		`{"text":"a","id":"x"}`:             "doesn't match",
		`{"text":"a","tags":["a","b","c"]}`: "at most 2 items",
		`{"text":"a","tags":[1]}`:           "tags[0]: must be string",
		`[1]`:                               "must be object",
	}
	for in, want := range cases {
		v, err := decodeJSON([]byte(in))
		if err != nil {
			t.Fatal(err)
		}
		err = s.Validate(v)
		switch {
		case want == "" && err != nil:
			t.Errorf("%s: unexpected %v", in, err)
		case want != "" && (err == nil || !strings.Contains(err.Error(), want)):
			t.Errorf("%s: got %v, want %q", in, err, want)
		}
	}
}

func mustJSON(t *testing.T, v any) string {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

// client drives a Server over pipes like an editor does.
type client struct {
	t     *testing.T
	in    *io.PipeWriter
	mu    sync.Mutex
	msgs  []map[string]any
	cond  *sync.Cond
	done  chan error
	reply func(m map[string]any) any // answers server→client requests
}

func start(t *testing.T, s *Server) *client {
	t.Helper()
	inR, inW := io.Pipe()
	outR, outW := io.Pipe()
	c := &client{t: t, in: inW, done: make(chan error, 1)}
	c.cond = sync.NewCond(&c.mu)
	go func() { c.done <- s.Serve(context.Background(), inR, outW); _ = outW.Close() }()
	go func() {
		sc := bufio.NewScanner(outR)
		sc.Buffer(make([]byte, 1<<20), 8<<20)
		for sc.Scan() {
			var m map[string]any
			if err := json.Unmarshal(sc.Bytes(), &m); err != nil {
				t.Errorf("server wrote a non-JSON line: %s", sc.Bytes())
				continue
			}
			if _, isReq := m["method"]; isReq && m["id"] != nil && c.reply != nil {
				c.send(map[string]any{"jsonrpc": "2.0", "id": m["id"], "result": c.reply(m)})
			}
			c.mu.Lock()
			c.msgs = append(c.msgs, m)
			c.cond.Broadcast()
			c.mu.Unlock()
		}
	}()
	t.Cleanup(func() { _ = inW.Close() })
	return c
}

func (c *client) send(m map[string]any) {
	b, _ := json.Marshal(m)
	if _, err := c.in.Write(append(b, '\n')); err != nil {
		c.t.Fatal(err)
	}
}

func (c *client) raw(line string) {
	if _, err := c.in.Write([]byte(line + "\n")); err != nil {
		c.t.Fatal(err)
	}
}

// wait returns the first message matching ok.
func (c *client) wait(ok func(map[string]any) bool) map[string]any {
	c.t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	timer := time.AfterFunc(5*time.Second, func() { c.mu.Lock(); c.cond.Broadcast(); c.mu.Unlock() })
	defer timer.Stop()
	c.mu.Lock()
	defer c.mu.Unlock()
	for {
		for _, m := range c.msgs {
			if ok(m) {
				return m
			}
		}
		if time.Now().After(deadline) {
			c.t.Fatalf("no matching message; got %v", c.msgs)
		}
		c.cond.Wait()
	}
}

func (c *client) response(id any) map[string]any {
	want, _ := json.Marshal(id)
	return c.wait(func(m map[string]any) bool {
		got, _ := json.Marshal(m["id"])
		_, isReq := m["method"]
		return !isReq && string(got) == string(want)
	})
}

func (c *client) call(id any, method string, params any) map[string]any {
	c.send(map[string]any{"jsonrpc": "2.0", "id": id, "method": method, "params": params})
	return c.response(id)
}

func testServer() *Server {
	slow := make(chan struct{})
	echo := NewTool("echo", "Echo", "Echoes text.", Annotations{ReadOnlyHint: true},
		func(_ context.Context, req *Request, in echoIn) (echoOut, string, error) {
			req.Progress("echoing")
			if in.Text == "fail" {
				return echoOut{}, "", Errorf("it failed on purpose")
			}
			return echoOut{Text: in.Text, Lines: []string{}}, "echo: " + in.Text, nil
		})
	wait := NewTool("wait", "Wait", "Waits until cancelled.", Annotations{},
		func(ctx context.Context, _ *Request, _ struct{}) (echoOut, string, error) {
			select {
			case <-ctx.Done():
				close(slow)
				return echoOut{}, "", ctx.Err()
			case <-time.After(5 * time.Second):
				return echoOut{}, "late", nil
			}
		})
	roots := NewTool("roots", "Roots", "Asks for roots.", Annotations{},
		func(ctx context.Context, req *Request, _ struct{}) (echoOut, string, error) {
			r, err := req.Roots(ctx)
			if err != nil {
				return echoOut{}, "", err
			}
			lines := []string{}
			for _, x := range r {
				lines = append(lines, x.URI)
			}
			return echoOut{Lines: lines}, strings.Join(lines, ","), nil
		})
	return &Server{
		Name: "t", Version: "1", Instructions: "be nice",
		Tools: func(context.Context) []Tool { return []Tool{echo, wait, roots} },
		Prompts: []Prompt{{Name: "p", Arguments: []PromptArgument{{Name: "x", Required: true}}, Get: func(_ context.Context, a map[string]string) (string, []PromptMessage, error) {
			return "d", []PromptMessage{{Role: "user", Content: TextContent("x=" + a["x"])}}, nil
		}}},
	}
}

func TestInitializeNegotiatesTheVersion(t *testing.T) {
	c := start(t, testServer())
	res := c.call(1, "initialize", map[string]any{"protocolVersion": "2025-06-18", "capabilities": map[string]any{}, "clientInfo": map[string]any{"name": "x", "version": "1"}})["result"].(map[string]any)
	if res["protocolVersion"] != "2025-06-18" || res["instructions"] != "be nice" {
		t.Errorf("initialize = %v", res)
	}
	caps := res["capabilities"].(map[string]any)
	if caps["tools"] == nil || caps["prompts"] == nil || caps["resources"] != nil {
		t.Errorf("capabilities = %v", caps)
	}
	res = c.call(2, "initialize", map[string]any{"protocolVersion": "1999-01-01", "capabilities": map[string]any{}, "clientInfo": map[string]any{"name": "x", "version": "1"}})["result"].(map[string]any)
	if res["protocolVersion"] != ProtocolVersions[0] {
		t.Errorf("an unknown version gets the newest: %v", res["protocolVersion"])
	}
}

func TestProtocolErrors(t *testing.T) {
	c := start(t, testServer())
	if e := c.call(1, "nope/nope", nil)["error"].(map[string]any); e["code"].(float64) != CodeMethodNotFound {
		t.Errorf("unknown method = %v", e)
	}
	c.raw(`{not json`)
	c.raw(`[{"jsonrpc":"2.0","id":9,"method":"ping"}]`)
	errs := 0
	c.wait(func(m map[string]any) bool {
		if e, ok := m["error"].(map[string]any); ok && m["id"] == nil {
			code := e["code"].(float64)
			if code == CodeParseError || code == CodeInvalidRequest {
				errs++
			}
		}
		return errs == 2
	})
	if r := c.call("p", "ping", nil); r["result"] == nil {
		t.Errorf("ping = %v", r)
	}
	if e := c.call(3, "tools/call", map[string]any{"name": "missing"})["error"].(map[string]any); !strings.Contains(e["message"].(string), "unknown tool") {
		t.Errorf("unknown tool = %v", e)
	}
}

func TestToolsListAndCall(t *testing.T) {
	c := start(t, testServer())
	tools := c.call(1, "tools/list", nil)["result"].(map[string]any)["tools"].([]any)
	first := tools[0].(map[string]any)
	if first["name"] != "echo" || first["inputSchema"] == nil || first["outputSchema"] == nil || first["annotations"].(map[string]any)["readOnlyHint"] != true {
		t.Errorf("tools/list = %v", first)
	}

	res := c.call(2, "tools/call", map[string]any{"name": "echo", "arguments": map[string]any{"text": "hi"}, "_meta": map[string]any{"progressToken": "tok"}})["result"].(map[string]any)
	if res["isError"] == true || res["structuredContent"].(map[string]any)["text"] != "hi" || res["content"].([]any)[0].(map[string]any)["text"] != "echo: hi" {
		t.Errorf("call = %v", res)
	}
	c.wait(func(m map[string]any) bool {
		p, _ := m["params"].(map[string]any)
		return m["method"] == "notifications/progress" && p["progressToken"] == "tok" && p["message"] == "echoing" && p["progress"].(float64) == 1
	})

	bad := c.call(3, "tools/call", map[string]any{"name": "echo", "arguments": map[string]any{"text": 1}})["result"].(map[string]any)
	if bad["isError"] != true || !strings.Contains(bad["content"].([]any)[0].(map[string]any)["text"].(string), "Invalid arguments: text: must be string") {
		t.Errorf("invalid arguments = %v", bad)
	}
	failed := c.call(4, "tools/call", map[string]any{"name": "echo", "arguments": map[string]any{"text": "fail"}})["result"].(map[string]any)
	if failed["isError"] != true || failed["content"].([]any)[0].(map[string]any)["text"] != "it failed on purpose" {
		t.Errorf("tool error = %v", failed)
	}
}

func TestCancelledRequestsStopAndGetNoAnswer(t *testing.T) {
	s := testServer()
	c := start(t, s)
	c.send(map[string]any{"jsonrpc": "2.0", "id": 7, "method": "tools/call", "params": map[string]any{"name": "wait", "arguments": map[string]any{}}})
	time.Sleep(50 * time.Millisecond)
	c.send(map[string]any{"jsonrpc": "2.0", "method": "notifications/cancelled", "params": map[string]any{"requestId": 7, "reason": "user"}})
	c.call(8, "ping", nil)
	time.Sleep(100 * time.Millisecond)
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, m := range c.msgs {
		if id, _ := m["id"].(float64); id == 7 {
			t.Errorf("a cancelled request must not be answered: %v", m)
		}
	}
}

func TestRootsAreAskedFromTheClient(t *testing.T) {
	c := start(t, testServer())
	c.reply = func(m map[string]any) any {
		if m["method"] != "roots/list" {
			t.Errorf("unexpected request %v", m)
		}
		return map[string]any{"roots": []any{map[string]any{"uri": "file:///work/shop", "name": "shop"}}}
	}
	c.call(1, "initialize", map[string]any{"protocolVersion": "2025-06-18", "capabilities": map[string]any{"roots": map[string]any{}}, "clientInfo": map[string]any{"name": "x", "version": "1"}})
	res := c.call(2, "tools/call", map[string]any{"name": "roots", "arguments": map[string]any{}})["result"].(map[string]any)
	if res["content"].([]any)[0].(map[string]any)["text"] != "file:///work/shop" {
		t.Errorf("roots = %v", res)
	}
}

func TestPrompts(t *testing.T) {
	c := start(t, testServer())
	list := c.call(1, "prompts/list", nil)["result"].(map[string]any)["prompts"].([]any)
	if list[0].(map[string]any)["name"] != "p" {
		t.Errorf("prompts/list = %v", list)
	}
	if e := c.call(2, "prompts/get", map[string]any{"name": "p"})["error"]; e == nil {
		t.Error("a missing required argument is an error")
	}
	got := c.call(3, "prompts/get", map[string]any{"name": "p", "arguments": map[string]any{"x": "1"}})["result"].(map[string]any)
	if got["messages"].([]any)[0].(map[string]any)["content"].(map[string]any)["text"] != "x=1" {
		t.Errorf("prompts/get = %v", got)
	}
}

func TestServeEndsAtEOF(t *testing.T) {
	s := testServer()
	err := s.Serve(context.Background(), strings.NewReader(`{"jsonrpc":"2.0","id":1,"method":"ping"}`+"\n"), io.Discard)
	if err != nil {
		t.Errorf("Serve = %v", err)
	}
}
