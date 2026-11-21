package api

import (
	"context"
	"net/http"
	"net/url"
	"strconv"
)

// One-off commands of a service (D105): `launchroom exec`.

// Command is one command of a service's catalogue.
type Command struct {
	Name        string   `json:"name"`
	Label       string   `json:"label"`
	Description *string  `json:"description"`
	Argv        []string `json:"argv"`
	Danger      bool     `json:"danger"` // it changes data: ask first
	Source      string   `json:"source"` // CommandSource*
}

// CommandCatalogue is GET /environments/{env}/services/{svc}/commands.
type CommandCatalogue struct {
	Framework     *string   `json:"framework"`
	Commands      []Command `json:"commands"`
	AwaitingBuild bool      `json:"awaitingBuild"`
}

// CommandRun is one one-off command run.
type CommandRun struct {
	ID              string   `json:"id"`
	Status          string   `json:"status"` // CommandRunStatus*
	Finished        bool     `json:"finished"`
	Argv            []string `json:"argv"`
	Name            *string  `json:"name"`
	ExitCode        *int     `json:"exitCode"`
	Error           *string  `json:"error"`
	TimeoutSeconds  int      `json:"timeoutSeconds"`
	OutputTruncated bool     `json:"outputTruncated"`
	Service         string   `json:"service"`
	Environment     string   `json:"environment"`
	Deployment      *string  `json:"deployment"`
	Server          *string  `json:"server"`
	RanBy           *string  `json:"ranBy"`
	ViaAPI          bool     `json:"viaApi"`
	CancelledBy     *string  `json:"cancelledBy"`
	CreatedAt       string   `json:"createdAt"`
	StartedAt       *string  `json:"startedAt"`
	FinishedAt      *string  `json:"finishedAt"`
	DurationMs      *int     `json:"durationMs"`
}

// CommandOutputLine is one line of a run's output; Stream is stdout,
// stderr or system (launchroom's own lines).
type CommandOutputLine struct {
	Number int    `json:"number"`
	Time   string `json:"time"`
	Stream string `json:"stream"`
	Text   string `json:"text"`
}

// CommandLinesEvent is the `lines` event of a run's output stream.
type CommandLinesEvent struct {
	Lines  []CommandOutputLine `json:"lines"`
	Cursor int                 `json:"cursor"`
}

// CommandStatusEvent is the `status` event of a run's output stream.
type CommandStatusEvent struct {
	Status   string `json:"status"`
	Finished bool   `json:"finished"`
}

// CommandEndEvent is the `end` event of a run's output stream.
type CommandEndEvent struct {
	Status   string  `json:"status"`
	ExitCode *int    `json:"exitCode"`
	Error    *string `json:"error"`
}

// StartCommandRunRequest is the body of POST …/command-runs: Argv or Name.
type StartCommandRunRequest struct {
	Argv           []string `json:"argv,omitempty"`
	Name           string   `json:"name,omitempty"`
	TimeoutSeconds int      `json:"timeoutSeconds,omitempty"`
}

func serviceCommandsPath(envID, serviceID string) string {
	return "/environments/" + seg(envID) + "/services/" + seg(serviceID)
}

// Commands is the service's command catalogue.
func (c *Client) Commands(ctx context.Context, envID, serviceID string) (CommandCatalogue, error) {
	var cat CommandCatalogue
	return cat, get(ctx, c, serviceCommandsPath(envID, serviceID)+"/commands", nil, &cat, nil)
}

// StartCommandRun runs a command in a new container of the live deployment.
func (c *Client) StartCommandRun(ctx context.Context, envID, serviceID string, body StartCommandRunRequest) (CommandRun, error) {
	var r CommandRun
	return r, c.do(ctx, request{method: http.MethodPost, path: serviceCommandsPath(envID, serviceID) + "/command-runs", body: body, idempotent: true}, &r, nil)
}

// CommandRun is GET /command-runs/{id}.
func (c *Client) CommandRun(ctx context.Context, id string) (CommandRun, error) {
	var r CommandRun
	return r, get(ctx, c, "/command-runs/"+seg(id), nil, &r, nil)
}

// CommandRunOutput is one page of a run's output after a cursor.
func (c *Client) CommandRunOutput(ctx context.Context, id string, after int) ([]CommandOutputLine, LogMeta, error) {
	var lines []CommandOutputLine
	var meta LogMeta
	err := get(ctx, c, "/command-runs/"+seg(id)+"/output", url.Values{"after": {strconv.Itoa(after)}}, &lines, &meta)
	return lines, meta, err
}

// CancelCommandRun stops a run (an ended run comes back as it is).
func (c *Client) CancelCommandRun(ctx context.Context, id string) (CommandRun, error) {
	var r CommandRun
	return r, c.do(ctx, request{method: http.MethodPost, path: "/command-runs/" + seg(id) + "/cancel", idempotent: true}, &r, nil)
}
