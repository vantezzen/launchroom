package controlplane

import (
	"context"
	"errors"
	"net/http"
	"testing"
	"time"
)

func TestRetry(t *testing.T) {
	calls := 0
	err := Retry(t.Context(), Backoff{Attempts: 3, Initial: time.Millisecond}, func() error {
		calls++
		return &APIError{Status: http.StatusServiceUnavailable}
	})
	if calls != 3 || err == nil {
		t.Fatalf("calls %d, err %v", calls, err)
	}
	calls = 0
	err = Retry(t.Context(), Backoff{Attempts: 3, Initial: time.Millisecond}, func() error {
		calls++
		return &APIError{Status: http.StatusConflict}
	})
	if calls != 1 || !IsLeaseLost(err) {
		t.Fatalf("a final answer was retried: calls %d, err %v", calls, err)
	}
	ctx, cancel := context.WithCancel(t.Context())
	cancel()
	if err := Retry(ctx, Backoff{Attempts: 3, Initial: time.Hour}, func() error { return errors.New("network down") }); !errors.Is(err, context.Canceled) {
		t.Fatalf("err %v", err)
	}
	if IsLeaseLost(&APIError{Status: http.StatusServiceUnavailable}) {
		t.Fatal("503 is not a lost lease")
	}
}
