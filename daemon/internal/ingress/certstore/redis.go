package certstore

import (
	"bytes"
	"compress/flate"
	"compress/zlib"
	"context"
	"crypto/aes"
	"crypto/cipher"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

// maxKeys bounds one storage scan.
const maxKeys = 50_000

// Redis reads Caddy's certificates from shared storage written by the
// caddy.storage.redis module (github.com/pberkel/caddy-storage-redis): each
// value is JSON {"value": base64, "modified", "size", "compression",
// "encryption"}, the value AES-256-GCM encrypted (nonce prefix) with the
// first 32 bytes of the encryption key and optionally flate/zlib compressed.
type Redis struct {
	Addr          string
	Username      secret.Secret
	Password      secret.Secret
	KeyPrefix     string
	EncryptionKey secret.Secret
	// Timeout bounds the connection and each command (default 5 s).
	Timeout time.Duration
}

func (r *Redis) open(ctx context.Context) (*conn, error) {
	t := r.Timeout
	if t <= 0 {
		t = 5 * time.Second
	}
	c, err := dial(ctx, r.Addr, t)
	if err != nil {
		return nil, fmt.Errorf("certificate storage %s: %w", r.Addr, err)
	}
	if pw := r.Password.Reveal(); pw != "" {
		args := []string{"AUTH", pw}
		if u := r.Username.Reveal(); u != "" {
			args = []string{"AUTH", u, pw}
		}
		if _, err := c.do(ctx, args...); err != nil {
			_ = c.Close()
			// The reply never echoes the password.
			return nil, fmt.Errorf("certificate storage %s: authentication failed: %w", r.Addr, err)
		}
	}
	return c, nil
}

// Ping checks that the storage is reachable and accepts the credentials.
func (r *Redis) Ping(ctx context.Context) error {
	c, err := r.open(ctx)
	if err != nil {
		return err
	}
	defer func() { _ = c.Close() }()
	v, err := c.do(ctx, "PING")
	if err != nil {
		return fmt.Errorf("certificate storage %s: %w", r.Addr, err)
	}
	if v != "PONG" {
		return fmt.Errorf("certificate storage %s: unexpected PING reply", r.Addr)
	}
	return nil
}

// Certificates returns every certificate (with its key when withKeys) in
// the storage. Values that do not decode are skipped.
func (r *Redis) Certificates(ctx context.Context, withKeys bool) ([]Cert, error) {
	c, err := r.open(ctx)
	if err != nil {
		return nil, err
	}
	defer func() { _ = c.Close() }()

	prefix := strings.TrimSuffix(r.KeyPrefix, "/") + "/"
	var keys []string
	cursor := "0"
	for {
		v, err := c.do(ctx, "SCAN", cursor, "MATCH", prefix+"certificates/*", "COUNT", "1000")
		if err != nil {
			return nil, fmt.Errorf("certificate storage scan: %w", err)
		}
		arr, ok := v.([]any)
		if !ok || len(arr) != 2 {
			return nil, errors.New("certificate storage scan: malformed reply")
		}
		next, _ := arr[0].(string)
		items, _ := arr[1].([]any)
		for _, it := range items {
			if k, ok := it.(string); ok {
				keys = append(keys, k)
			}
		}
		if len(keys) > maxKeys {
			return nil, fmt.Errorf("certificate storage scan: more than %d keys", maxKeys)
		}
		if next == "0" || next == "" {
			break
		}
		cursor = next
	}

	files := map[[3]string][]byte{}
	for _, k := range keys {
		issuer, name, ext, ok := parseKey(strings.TrimPrefix(k, prefix))
		if !ok || (ext == ".key" && !withKeys) {
			continue
		}
		v, err := c.do(ctx, "GET", k)
		if err != nil {
			var er errReply
			if errors.As(err, &er) { // e.g. WRONGTYPE: a directory index
				continue
			}
			return nil, fmt.Errorf("certificate storage get: %w", err)
		}
		raw, ok := v.(string)
		if !ok {
			continue
		}
		data, err := r.decode([]byte(raw))
		if err != nil {
			continue
		}
		files[[3]string{issuer, name, ext}] = data
	}
	return sorted(assemble(files)), nil
}

type storageData struct {
	Value       []byte `json:"value"`
	Compression int    `json:"compression"`
	Encryption  int    `json:"encryption"`
}

func (r *Redis) decode(raw []byte) ([]byte, error) {
	var sd storageData
	if err := json.Unmarshal(raw, &sd); err != nil {
		return nil, err
	}
	v := sd.Value
	if sd.Encryption > 0 {
		key := r.EncryptionKey.Reveal()
		if len(key) < 32 {
			return nil, errors.New("certstore: value is encrypted but no key is configured")
		}
		block, err := aes.NewCipher([]byte(key[:32]))
		if err != nil {
			return nil, err
		}
		gcm, err := cipher.NewGCM(block)
		if err != nil {
			return nil, err
		}
		if len(v) < gcm.NonceSize()+gcm.Overhead() {
			return nil, errors.New("certstore: encrypted value too short")
		}
		if v, err = gcm.Open(nil, v[:gcm.NonceSize()], v[gcm.NonceSize():], nil); err != nil {
			return nil, errors.New("certstore: cannot decrypt value")
		}
	}
	switch sd.Compression {
	case 0:
		return v, nil
	case 1:
		return io.ReadAll(io.LimitReader(flate.NewReader(bytes.NewReader(v)), maxBulk))
	case 2:
		zr, err := zlib.NewReader(bytes.NewReader(v))
		if err != nil {
			return nil, err
		}
		return io.ReadAll(io.LimitReader(zr, maxBulk))
	default:
		return nil, errors.New("certstore: unknown compression")
	}
}
