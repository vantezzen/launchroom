package lrtrace

import (
	"io"
	"net/http"
	"sync/atomic"

	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
)

// traceWriter adds the trace header in the final WriteHeader, whatever
// path the status takes (Write, Flush and ReadFrom write an implicit 200).
// Informational statuses pass through, except 101 (WebSocket), whose
// connection is then hijacked through Unwrap.
//
// The header is added at the last moment on purpose: Coraza's response
// phase deletes every response header before writing its own status.
type traceWriter struct {
	*caddyhttp.ResponseWriterWrapper
	h    *Handler
	st   *reqState
	r    *http.Request
	done atomic.Bool
}

// claim reports whether the caller is the one to set the trace.
func (w *traceWriter) claim() bool { return w.done.CompareAndSwap(false, true) }

func (w *traceWriter) WriteHeader(status int) {
	if (status < 100 || status >= 200 || status == http.StatusSwitchingProtocols) && w.claim() {
		w.h.setTrace(w.Header(), w.r, w.st, status, nil)
	}
	w.ResponseWriterWrapper.WriteHeader(status)
}

func (w *traceWriter) Write(b []byte) (int, error) {
	if !w.done.Load() {
		w.WriteHeader(http.StatusOK)
	}
	return w.ResponseWriterWrapper.Write(b)
}

// ReadFrom would otherwise reach the underlying writer without our
// WriteHeader.
func (w *traceWriter) ReadFrom(r io.Reader) (int64, error) {
	if !w.done.Load() {
		w.WriteHeader(http.StatusOK)
	}
	return w.ResponseWriterWrapper.ReadFrom(r)
}

// FlushError is what http.ResponseController calls first.
func (w *traceWriter) FlushError() error {
	if !w.done.Load() {
		w.WriteHeader(http.StatusOK)
	}
	return http.NewResponseController(w.ResponseWriterWrapper.ResponseWriter).Flush()
}

// Flush implements http.Flusher.
func (w *traceWriter) Flush() { _ = w.FlushError() }

var (
	_ http.Flusher  = (*traceWriter)(nil)
	_ io.ReaderFrom = (*traceWriter)(nil)
)
