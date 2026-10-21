package controlplane

import (
	"bytes"
	"context"
	"errors"
	"net/http"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const transferID = state.TransferID("dtr_01J9Z8X7W6V5T4S3R2Q1P0N9R0")

func TestTransferChunks(t *testing.T) {
	id, _ := identity.Generate()
	f, srv := newFakeCP(t, id.SigningPublicKey())
	c := clientFor(t, id, srv.URL, string(serverID), identity.KeySet{kid: f.pub()})
	ctx := context.Background()

	t.Run("fetch verifies the signature", func(t *testing.T) {
		f.set(func(r *http.Request, _ []byte) reply {
			if r.Method != http.MethodGet || r.URL.Path != "/daemon/v1/transfers/"+string(transferID)+"/chunks/3" {
				t.Errorf("unexpected %s %s", r.Method, r.URL.Path)
			}
			if r.Header.Get("Accept") != "application/octet-stream" {
				t.Errorf("accept %q", r.Header.Get("Accept"))
			}
			return reply{status: 200, body: "\x00\x01chunk"}
		})
		got, err := c.FetchTransferChunk(ctx, transferID, 3, 1<<20)
		if err != nil || string(got) != "\x00\x01chunk" {
			t.Fatalf("%q %v", got, err)
		}
		f.set(func(*http.Request, []byte) reply { return reply{status: 200, body: "x", sign: "other"} })
		var se *SignatureError
		if _, err := c.FetchTransferChunk(ctx, transferID, 3, 1<<20); !errors.As(err, &se) {
			t.Fatalf("forged chunk accepted: %v", err)
		}
		f.set(func(*http.Request, []byte) reply { return reply{status: 200, body: strings.Repeat("a", 10)} })
		if _, err := c.FetchTransferChunk(ctx, transferID, 3, 5); err == nil {
			t.Fatal("oversized chunk accepted")
		}
		f.set(func(*http.Request, []byte) reply { return reply{status: 410, body: "gone", sign: "none"} })
		var api *APIError
		if _, err := c.FetchTransferChunk(ctx, transferID, 3, 5); !errors.As(err, &api) || api.Status != 410 {
			t.Fatalf("%v", err)
		}
	})

	t.Run("put sends the raw body", func(t *testing.T) {
		data := bytes.Repeat([]byte{0xff, 0x00}, 1000)
		f.set(func(r *http.Request, body []byte) reply {
			if r.Method != http.MethodPut || r.Header.Get("Content-Type") != "application/octet-stream" || !bytes.Equal(body, data) {
				t.Errorf("bad put %s %q", r.Method, r.Header.Get("Content-Type"))
			}
			return reply{status: 204, sign: "none"}
		})
		if err := c.PutTransferChunk(ctx, transferID, 0, data); err != nil {
			t.Fatal(err)
		}
		f.set(func(*http.Request, []byte) reply { return reply{status: 404, sign: "none"} })
		if err := c.PutTransferChunk(ctx, transferID, 0, data); err == nil {
			t.Fatal("404 accepted")
		}
	})
}
