package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/sse"
)

// ErrStopStream ends Stream without an error when returned by a handler.
var ErrStopStream = errors.New("stop stream")

// Stream follows an SSE endpoint (path under /api/v1), reconnecting with
// Last-Event-ID when the server closes the stream (`reconnect`, EOF) or the
// connection drops, until the handler returns ErrStopStream, an `end`
// event arrives, or ctx is done. lastID resumes from a cursor.
func (c *Client) Stream(ctx context.Context, path string, q url.Values, lastID string, handle func(sse.Event) error) error {
	failures := 0
	for {
		reconnect, err := c.streamOnce(ctx, path, q, &lastID, handle)
		if errors.Is(err, ErrStopStream) {
			return nil
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if err != nil {
			var apiErr *Error
			// An HTTP error answer is final; transport errors retry.
			if errors.As(err, &apiErr) && apiErr.Status != 0 && apiErr.Status != http.StatusServiceUnavailable && apiErr.Status != http.StatusBadGateway {
				return err
			}
			failures++
			if failures > 5 {
				return err
			}
		} else if !reconnect {
			return nil
		} else {
			failures = 0
		}
		wait := time.Duration(failures) * time.Second
		if wait == 0 {
			wait = 200 * time.Millisecond
		}
		if err := c.Sleep(ctx, wait); err != nil {
			return err
		}
	}
}

// streamOnce reads one connection; reconnect says whether to come back.
func (c *Client) streamOnce(ctx context.Context, path string, q url.Values, lastID *string, handle func(sse.Event) error) (bool, error) {
	query := url.Values{}
	for k, v := range q {
		query[k] = v
	}
	headers := map[string]string{"Accept": "text/event-stream"}
	if *lastID != "" {
		headers["Last-Event-ID"] = *lastID
		query.Set("after", *lastID)
	}
	resp, err := c.send(ctx, c.StreamHTTP, request{method: http.MethodGet, path: path, query: query}, nil, "", headers)
	if err != nil {
		return true, err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
		return false, errorFrom(resp, raw)
	}
	if ct := resp.Header.Get("Content-Type"); !strings.HasPrefix(ct, "text/event-stream") {
		return false, &Error{Status: resp.StatusCode, Code: "bad_response", Message: "the dashboard did not answer with an event stream"}
	}
	r := sse.NewReader(resp.Body)
	for {
		ev, err := r.Next()
		if id := r.LastID(); id != "" {
			*lastID = id
		}
		if errors.Is(err, io.EOF) {
			return true, nil
		}
		if err != nil {
			if ctx.Err() != nil {
				return false, ctx.Err()
			}
			return true, &Error{Code: "network_error", Message: "the log stream broke: " + err.Error()}
		}
		switch ev.Event {
		case "reconnect":
			return true, nil
		case "end":
			if err := handle(ev); err != nil {
				return false, err
			}
			return false, ErrStopStream
		}
		if err := handle(ev); err != nil {
			return false, err
		}
	}
}
