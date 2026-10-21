package api

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Data import and export (docs/14 §12, D55). Tokens need the opt-in
// ability `data:transfer`.

// Transfer is an uploaded or exported file the dashboard holds in chunks.
type Transfer struct {
	ID             string  `json:"id"`
	Purpose        string  `json:"purpose"`
	Direction      string  `json:"direction"`
	Status         string  `json:"status"` // receiving | ready | failed | expired
	FileName       string  `json:"fileName"`
	SizeBytes      int64   `json:"sizeBytes"`
	ChunkBytes     int64   `json:"chunkBytes"`
	Chunks         int     `json:"chunks"`
	ReceivedChunks []int   `json:"receivedChunks"`
	Format         *string `json:"format"`
	Compressed     bool    `json:"compressed"`
	SourceVersion  *string `json:"sourceVersion"`
	Detail         *string `json:"detail"`
	Error          *string `json:"error"`
	CreatedAt      string  `json:"createdAt"`
	ExpiresAt      *string `json:"expiresAt"`
}

// ChunkReceipt is the answer to a chunk upload.
type ChunkReceipt struct {
	Index    int `json:"index"`
	Received int `json:"received"`
	Chunks   int `json:"chunks"`
}

// VolumeRef names a service volume.
type VolumeRef struct {
	ID        string `json:"id"`
	MountPath string `json:"mountPath"`
}

// ImportResult is what an import left in the target.
type ImportResult struct {
	Tables        int64    `json:"tables"`
	Files         int64    `json:"files"`
	SizeBytes     int64    `json:"sizeBytes"`
	ServerVersion *string  `json:"serverVersion"`
	SourceVersion *string  `json:"sourceVersion"`
	Format        *string  `json:"format"`
	Warnings      []string `json:"warnings"`
	DurationMs    int64    `json:"durationMs"`
}

// ImportRun is one import into a database or volume.
type ImportRun struct {
	ID             string        `json:"id"`
	Subject        string        `json:"subject"`
	Status         string        `json:"status"`
	SourceKind     string        `json:"sourceKind"`
	SourceLabel    string        `json:"sourceLabel"`
	Replace        bool          `json:"replace"`
	StopDependents bool          `json:"stopDependents"`
	Volume         *VolumeRef    `json:"volume"`
	Step           *string       `json:"step"`
	Result         *ImportResult `json:"result"`
	Error          *string       `json:"error"`
	CreatedAt      string        `json:"createdAt"`
	StartedAt      *string       `json:"startedAt"`
	FinishedAt     *string       `json:"finishedAt"`
}

// Finished reports whether the import ended.
func (r ImportRun) Finished() bool {
	return r.Status == "succeeded" || r.Status == "failed" || r.Status == "cancelled"
}

// ExportRun is one export (download) of a database or volume.
type ExportRun struct {
	ID         string     `json:"id"`
	Subject    string     `json:"subject"`
	Source     string     `json:"source"` // live | snapshot
	Snapshot   *string    `json:"snapshot"`
	Volume     *VolumeRef `json:"volume"`
	Status     string     `json:"status"` // preparing | ready | failed | expired
	FileName   string     `json:"fileName"`
	SizeBytes  int64      `json:"sizeBytes"`
	Format     *string    `json:"format"`
	Compressed bool       `json:"compressed"`
	Error      *string    `json:"error"`
	CreatedAt  string     `json:"createdAt"`
	ReadyAt    *string    `json:"readyAt"`
	ExpiresAt  *string    `json:"expiresAt"`
}

// DownloadLink is a one-use link to an export (5 minutes).
type DownloadLink struct {
	URL       string `json:"url"`
	ExpiresAt string `json:"expiresAt"`
}

// CreateTransferRequest starts an upload.
type CreateTransferRequest struct {
	Purpose   string `json:"purpose"` // database_import | volume_import
	FileName  string `json:"fileName"`
	SizeBytes int64  `json:"sizeBytes"`
	Volume    string `json:"volume,omitempty"`
}

// ImportRequest starts an import.
type ImportRequest struct {
	Transfer        string `json:"transfer,omitempty"`
	Replace         bool   `json:"replace"`
	StopDependents  bool   `json:"stopDependents"`
	Confirmation    string `json:"confirmation,omitempty"`
	Volume          string `json:"volume,omitempty"`
	StripComponents int    `json:"stripComponents,omitempty"`
}

// ExportRequest starts an export: the live data or a backup snapshot.
type ExportRequest struct {
	Volume   string `json:"volume,omitempty"`
	Snapshot string `json:"snapshot,omitempty"`
}

func servicePath(envID, serviceID, rest string) string {
	return "/environments/" + seg(envID) + "/services/" + seg(serviceID) + rest
}

// CreateTransfer is POST …/transfers.
func (c *Client) CreateTransfer(ctx context.Context, envID, serviceID string, body CreateTransferRequest) (Transfer, error) {
	var t Transfer
	return t, c.do(ctx, request{method: http.MethodPost, path: servicePath(envID, serviceID, "/transfers"), body: body, idempotent: true}, &t, nil)
}

// Transfer is GET /transfers/{id}.
func (c *Client) Transfer(ctx context.Context, id string) (Transfer, error) {
	var t Transfer
	return t, get(ctx, c, "/transfers/"+seg(id), nil, &t, nil)
}

// CompleteTransfer is POST /transfers/{id}/complete: the dashboard checks
// every chunk arrived and reads the file's format.
func (c *Client) CompleteTransfer(ctx context.Context, id string) (Transfer, error) {
	var t Transfer
	return t, c.do(ctx, request{method: http.MethodPost, path: "/transfers/" + seg(id) + "/complete", idempotent: true}, &t, nil)
}

// DeleteTransfer is DELETE /transfers/{id}.
func (c *Client) DeleteTransfer(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/transfers/" + seg(id)}, nil, nil)
}

// ChunkDigest is the hex SHA-256 of one chunk (X-Chunk-Sha256).
func ChunkDigest(chunk []byte) string {
	sum := sha256.Sum256(chunk)
	return hex.EncodeToString(sum[:])
}

// PutChunk uploads chunk index of a transfer. Chunks are idempotent, so a
// network error, a 429 or a 5xx is retried (up to 5 attempts, backoff).
func (c *Client) PutChunk(ctx context.Context, id string, index int, chunk []byte) (ChunkReceipt, error) {
	r := request{method: http.MethodPut, path: "/transfers/" + seg(id) + "/chunks/" + strconv.Itoa(index)}
	headers := map[string]string{"Content-Type": "application/octet-stream", "X-Chunk-Sha256": ChunkDigest(chunk)}
	hc := c.TransferHTTP
	if hc == nil {
		hc = c.HTTP
	}
	wait := time.Second
	var last error
	for attempt := 1; attempt <= 5; attempt++ {
		var receipt ChunkReceipt
		resp, err := c.send(ctx, hc, r, chunk, "", headers) //nolint:bodyclose // decode closes it
		if err == nil {
			err = decode(resp, &receipt, nil)
			if err == nil {
				return receipt, nil
			}
		}
		if ctx.Err() != nil {
			return ChunkReceipt{}, ctx.Err()
		}
		var apiErr *Error
		if errors.As(err, &apiErr) && apiErr.Status != 0 && apiErr.Status != http.StatusTooManyRequests && apiErr.Status < 500 {
			return ChunkReceipt{}, err
		}
		last = err
		if attempt < 5 {
			d := wait
			if apiErr != nil && apiErr.RetryAfter > 0 && apiErr.RetryAfter <= time.Minute {
				d = apiErr.RetryAfter
			}
			if err := c.Sleep(ctx, d); err != nil {
				return ChunkReceipt{}, err
			}
			wait *= 2
		}
	}
	return ChunkReceipt{}, last
}

// StartImport is POST …/imports.
func (c *Client) StartImport(ctx context.Context, envID, serviceID string, body ImportRequest) (ImportRun, error) {
	var r ImportRun
	return r, c.do(ctx, request{method: http.MethodPost, path: servicePath(envID, serviceID, "/imports"), body: body, idempotent: true}, &r, nil)
}

// Import is GET /imports/{id}.
func (c *Client) Import(ctx context.Context, id string) (ImportRun, error) {
	var r ImportRun
	return r, get(ctx, c, "/imports/"+seg(id), nil, &r, nil)
}

// StartExport is POST …/exports.
func (c *Client) StartExport(ctx context.Context, envID, serviceID string, body ExportRequest) (ExportRun, error) {
	var r ExportRun
	return r, c.do(ctx, request{method: http.MethodPost, path: servicePath(envID, serviceID, "/exports"), body: body, idempotent: true}, &r, nil)
}

// Export is GET /exports/{id}.
func (c *Client) Export(ctx context.Context, id string) (ExportRun, error) {
	var r ExportRun
	return r, get(ctx, c, "/exports/"+seg(id), nil, &r, nil)
}

// ExportLink is POST /exports/{id}/link: a fresh one-use download link.
func (c *Client) ExportLink(ctx context.Context, id string) (DownloadLink, error) {
	var l DownloadLink
	return l, c.do(ctx, request{method: http.MethodPost, path: "/exports/" + seg(id) + "/link"}, &l, nil)
}

// Download is an open download stream.
type Download struct {
	Body io.ReadCloser
	// Size is Content-Length (-1 when the dashboard didn't send one).
	Size     int64
	FileName string
}

// OpenDownload opens a one-use link. The link must point at this
// dashboard's /api/v1/downloads/, so the token never goes anywhere else.
func (c *Client) OpenDownload(ctx context.Context, link string) (*Download, error) {
	prefix := c.BaseURL + "/api/v1/downloads/"
	if !strings.HasPrefix(link, prefix) || strings.ContainsAny(link[len(prefix):], "/?#") {
		return nil, &Error{Code: "bad_response", Message: "the download link does not point at this dashboard"}
	}
	path := "/downloads/" + link[len(prefix):]
	resp, err := c.send(ctx, c.StreamHTTP, request{method: http.MethodGet, path: path}, nil, "", map[string]string{"Accept": "application/octet-stream"}) //nolint:bodyclose // the caller closes Download.Body; decode closes errors
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusOK {
		return nil, decode(resp, nil, nil)
	}
	return &Download{Body: resp.Body, Size: resp.ContentLength, FileName: AttachmentName(resp.Header.Get("Content-Disposition"))}, nil
}

// AttachmentName returns a safe file name from a Content-Disposition
// header: no directories, no control characters, no leading dots ("" when
// there is none).
func AttachmentName(header string) string {
	const key = "filename="
	i := strings.Index(strings.ToLower(header), key)
	if i < 0 {
		return ""
	}
	name := strings.TrimSpace(header[i+len(key):])
	if j := strings.IndexByte(name, ';'); j >= 0 {
		name = name[:j]
	}
	name = strings.Trim(strings.TrimSpace(name), `"`)
	if k := strings.LastIndexAny(name, `/\`); k >= 0 {
		name = name[k+1:]
	}
	name = strings.Map(func(r rune) rune {
		switch {
		case r < 0x20 || r == 0x7f, strings.ContainsRune(`<>:"|?*`, r):
			return -1
		}
		return r
	}, name)
	name = strings.TrimLeft(name, ". ")
	if len(name) > 200 {
		name = name[:200]
	}
	return name
}
