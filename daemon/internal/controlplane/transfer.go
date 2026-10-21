package controlplane

import (
	"context"
	"fmt"
	"net/http"
	"strconv"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TransferTimeout bounds one transfer chunk request (D55, docs/14 §12.2): a
// chunk of up to 32 MiB over a slow uplink takes longer than the default.
const TransferTimeout = 5 * time.Minute

// timeoutKey overrides the per-request timeout for one call.
type timeoutKey struct{}

// rawBody is a request body sent as is (not JSON-encoded).
type rawBody struct {
	data        []byte
	contentType string
}

// FetchTransferChunk returns chunk n (0-based) of an uploaded transfer:
// GET /daemon/v1/transfers/{id}/chunks/{n}. The body must be signed by a
// trusted control-plane key (X-LR-Signature) and at most limit bytes.
func (c *Client) FetchTransferChunk(ctx context.Context, id state.TransferID, n int, limit int64) ([]byte, error) {
	if err := id.Validate(); err != nil {
		return nil, fmt.Errorf("controlplane: %w", err)
	}
	if n < 0 || limit < 1 {
		return nil, fmt.Errorf("controlplane: invalid chunk %d", n)
	}
	ctx = context.WithValue(ctx, timeoutKey{}, TransferTimeout)
	r, err := c.do(ctx, http.MethodGet, "transfers/"+string(id)+"/chunks/"+strconv.Itoa(n), nil, nil, http.Header{"Accept": {"application/octet-stream"}}, limit)
	if err != nil {
		return nil, err
	}
	if r.status != http.StatusOK {
		return nil, statusError(r)
	}
	body, _, err := c.verified(r)
	if err != nil {
		return nil, err
	}
	return body, nil
}

// PutTransferChunk uploads chunk n (0-based) of an export:
// PUT /daemon/v1/transfers/{id}/chunks/{n}. The request signature covers
// the body (content-digest).
func (c *Client) PutTransferChunk(ctx context.Context, id state.TransferID, n int, data []byte) error {
	if err := id.Validate(); err != nil {
		return fmt.Errorf("controlplane: %w", err)
	}
	if n < 0 {
		return fmt.Errorf("controlplane: invalid chunk %d", n)
	}
	ctx = context.WithValue(ctx, timeoutKey{}, TransferTimeout)
	r, err := c.do(ctx, http.MethodPut, "transfers/"+string(id)+"/chunks/"+strconv.Itoa(n), nil, rawBody{data: data, contentType: "application/octet-stream"}, nil, maxResponseBytes)
	if err != nil {
		return err
	}
	switch r.status {
	case http.StatusOK, http.StatusNoContent:
		return nil
	default:
		return statusError(r)
	}
}
