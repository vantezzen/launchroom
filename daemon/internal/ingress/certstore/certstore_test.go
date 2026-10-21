package certstore

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/flate"
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"io"
	"math/big"
	"net"
	"os"
	"path"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

// testCert returns a PEM certificate + key for names valid in [nb, na).
func testCert(t *testing.T, issuerCN string, nb, na time.Time, names ...string) ([]byte, []byte) {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()),
		Subject:      pkix.Name{CommonName: names[0]},
		Issuer:       pkix.Name{CommonName: issuerCN},
		DNSNames:     names,
		NotBefore:    nb,
		NotAfter:     na,
	}
	parent := &x509.Certificate{Subject: pkix.Name{Organization: []string{"Test CA"}, CommonName: issuerCN}, SerialNumber: big.NewInt(1)}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, parent, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	kb, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb})
}

// fakeRedis is a tiny RESP2 server: AUTH, PING, SCAN (one page), GET.
type fakeRedis struct {
	user, password string
	mu             sync.Mutex
	data           map[string]string
	zsets          map[string]bool
}

func (f *fakeRedis) serve(t *testing.T) string {
	t.Helper()
	var lc net.ListenConfig
	ln, err := lc.Listen(context.Background(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = ln.Close() })
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go f.handle(c)
		}
	}()
	return ln.Addr().String()
}

func (f *fakeRedis) handle(c net.Conn) {
	defer func() { _ = c.Close() }()
	r := bufio.NewReader(c)
	authed := f.password == ""
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			return
		}
		n, _ := strconv.Atoi(strings.TrimSpace(line[1:]))
		args := make([]string, 0, n)
		for range n {
			l, _ := r.ReadString('\n')
			size, _ := strconv.Atoi(strings.TrimSpace(l[1:]))
			b := make([]byte, size+2)
			if _, err := io.ReadFull(r, b); err != nil {
				return
			}
			args = append(args, string(b[:size]))
		}
		f.mu.Lock()
		reply := f.reply(args, &authed)
		f.mu.Unlock()
		if _, err := c.Write([]byte(reply)); err != nil {
			return
		}
	}
}

func bulk(s string) string { return "$" + strconv.Itoa(len(s)) + "\r\n" + s + "\r\n" }

func (f *fakeRedis) reply(args []string, authed *bool) string {
	switch strings.ToUpper(args[0]) {
	case "AUTH":
		u, p := "default", args[len(args)-1]
		if len(args) == 3 {
			u = args[1]
		}
		if p == f.password && (f.user == "" || u == f.user) {
			*authed = true
			return "+OK\r\n"
		}
		return "-WRONGPASS invalid username-password pair or user is disabled.\r\n"
	}
	if !*authed {
		return "-NOAUTH Authentication required.\r\n"
	}
	switch strings.ToUpper(args[0]) {
	case "PING":
		return "+PONG\r\n"
	case "SCAN":
		pattern := strings.TrimSuffix(args[3], "*")
		var keys []string
		for k := range f.data {
			if strings.HasPrefix(k, pattern) {
				keys = append(keys, k)
			}
		}
		for k := range f.zsets {
			if strings.HasPrefix(k, pattern) {
				keys = append(keys, k)
			}
		}
		out := "*2\r\n" + bulk("0") + "*" + strconv.Itoa(len(keys)) + "\r\n"
		for _, k := range keys {
			out += bulk(k)
		}
		return out
	case "GET":
		if f.zsets[args[1]] {
			return "-WRONGTYPE Operation against a key holding the wrong kind of value\r\n"
		}
		v, ok := f.data[args[1]]
		if !ok {
			return "$-1\r\n"
		}
		return bulk(v)
	}
	return "-ERR unknown command\r\n"
}

// encode stores a value like caddy-storage-redis (encrypted, optionally flate-compressed).
func encode(t *testing.T, key string, value []byte, compress bool) string {
	t.Helper()
	comp := 0
	if compress {
		var buf bytes.Buffer
		w, _ := flate.NewWriter(&buf, flate.BestCompression)
		_, _ = w.Write(value)
		_ = w.Close()
		value, comp = buf.Bytes(), 1
	}
	block, err := aes.NewCipher([]byte(key[:32]))
	if err != nil {
		t.Fatal(err)
	}
	gcm, _ := cipher.NewGCM(block)
	nonce := make([]byte, gcm.NonceSize())
	_, _ = rand.Read(nonce)
	sealed := gcm.Seal(nonce, nonce, value, nil)
	raw, _ := json.Marshal(map[string]any{"value": sealed, "modified": time.Now(), "size": len(value), "compression": comp, "encryption": 1})
	return string(raw)
}

const encKey = "0123456789abcdef0123456789abcdef-extra"

func TestRedisCertificates(t *testing.T) {
	now := time.Now()
	crt, key := testCert(t, "R12", now.Add(-time.Hour), now.Add(60*24*time.Hour), "shop.example.com")
	wcrt, wkey := testCert(t, "E7", now.Add(-time.Hour), now.Add(45*24*time.Hour), "*.shop.apps.example.com")
	dir := "lr-caddy/certificates/acme-v02.api.letsencrypt.org-directory/"
	f := &fakeRedis{user: "caddy", password: "pw", data: map[string]string{
		dir + "shop.example.com/shop.example.com.crt":                               encode(t, encKey, crt, false),
		dir + "shop.example.com/shop.example.com.key":                               encode(t, encKey, key, true),
		dir + "shop.example.com/shop.example.com.json":                              encode(t, encKey, []byte("{}"), false),
		dir + "wildcard_.shop.apps.example.com/wildcard_.shop.apps.example.com.crt": encode(t, encKey, wcrt, true),
		dir + "wildcard_.shop.apps.example.com/wildcard_.shop.apps.example.com.key": encode(t, encKey, wkey, false),
		"lr-caddy/certificates/../../etc/passwd.crt":                                encode(t, encKey, crt, false),
		"other/certificates/x/y/y.crt":                                              encode(t, encKey, crt, false),
	}, zsets: map[string]bool{"lr-caddy/certificates/acme-v02.api.letsencrypt.org-directory": true}}
	addr := f.serve(t)
	r := &Redis{Addr: addr, Username: "caddy", Password: "pw", KeyPrefix: "lr-caddy", EncryptionKey: encKey}
	ctx := context.Background()
	if err := r.Ping(ctx); err != nil {
		t.Fatal(err)
	}
	certs, err := r.Certificates(ctx, true)
	if err != nil {
		t.Fatal(err)
	}
	if len(certs) != 2 {
		t.Fatalf("certs = %d", len(certs))
	}
	ix := NewIndex(certs)
	c, wild := ix.Lookup("api-production.shop.apps.example.com", now)
	if c == nil || !wild || !bytes.Equal(c.KeyPEM, wkey) {
		t.Fatalf("wildcard lookup = %v %v", c, wild)
	}
	c, wild = ix.Lookup("shop.example.com", now)
	if c == nil || wild || Issuer(c.Leaf) != "Test CA R12" {
		t.Fatalf("exact lookup = %v %v", c, wild)
	}
	if c, _ := ix.Lookup("a.b.shop.apps.example.com", now); c != nil {
		t.Fatal("wildcard must cover one label only")
	}

	// Without keys, only certificates are read.
	certs, err = r.Certificates(ctx, false)
	if err != nil || len(certs) != 2 || certs[0].KeyPEM != nil {
		t.Fatalf("without keys: %v %v", err, certs)
	}

	bad := &Redis{Addr: addr, Username: "caddy", Password: "nope", KeyPrefix: "lr-caddy"}
	err = bad.Ping(ctx)
	if err == nil || strings.Contains(err.Error(), "nope") {
		t.Fatalf("bad password: %v", err)
	}
	wrongKey := &Redis{Addr: addr, Username: "caddy", Password: "pw", KeyPrefix: "lr-caddy", EncryptionKey: secret.Secret(strings.Repeat("x", 32))}
	certs, err = wrongKey.Certificates(ctx, false)
	if err != nil || len(certs) != 0 {
		t.Fatalf("wrong key must decode nothing: %v %d", err, len(certs))
	}
	unreachable := &Redis{Addr: "127.0.0.1:1", Timeout: time.Second}
	if err := unreachable.Ping(ctx); err == nil {
		t.Fatal("unreachable storage pinged")
	}
}

func TestIndexPrefersValid(t *testing.T) {
	now := time.Now()
	old, _ := testCert(t, "R10", now.Add(-100*24*time.Hour), now.Add(-time.Hour), "a.example.com")
	cur, _ := testCert(t, "R11", now.Add(-time.Hour), now.Add(30*24*time.Hour), "a.example.com")
	l1, _ := leaf(old)
	l2, _ := leaf(cur)
	ix := NewIndex([]Cert{{Name: "a", Leaf: l1}, {Name: "b", Leaf: l2}})
	if c, _ := ix.Lookup("a.example.com", now); c == nil || c.Name != "b" {
		t.Fatalf("lookup = %v", c)
	}
	ix = NewIndex([]Cert{{Name: "a", Leaf: l1}})
	if c, _ := ix.Lookup("a.example.com", now); c == nil || c.Name != "a" {
		t.Fatal("expired certificate must still be found when nothing else exists")
	}
}

func TestFromTarAndMirror(t *testing.T) {
	now := time.Now()
	crt, key := testCert(t, "R12", now.Add(-time.Hour), now.Add(time.Hour), "a.example.com")
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	add := func(name string, data []byte) {
		_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: name, Mode: 0o600, Size: int64(len(data))})
		_, _ = tw.Write(data)
	}
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "certificates/", Mode: 0o700})
	add("certificates/acme/a.example.com/a.example.com.crt", crt)
	add("certificates/acme/a.example.com/a.example.com.key", key)
	add("certificates/acme/a.example.com/a.example.com.json", []byte("{}"))
	add("certificates/acme/b/c.crt", crt) // name mismatch: ignored
	_ = tw.Close()

	certs, err := FromTar(buf.Bytes(), false)
	if err != nil || len(certs) != 1 || certs[0].KeyPEM != nil || certs[0].Name != "a.example.com" {
		t.Fatalf("FromTar = %v %v", certs, err)
	}
	certs, err = FromTar(buf.Bytes(), true)
	if err != nil || len(certs) != 1 || !bytes.Equal(certs[0].KeyPEM, key) {
		t.Fatalf("FromTar with keys = %v", err)
	}

	m := Mirror{Dir: filepath.Join(t.TempDir(), "certs")}
	if err := os.MkdirAll(m.Dir, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(m.Dir, "stale.example.com.crt"), crt, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := m.Replace(certs); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(m.Dir, "stale.example.com.crt")); !os.IsNotExist(err) {
		t.Fatal("stale mirror file kept")
	}
	st, err := os.Stat(filepath.Join(m.Dir, "a.example.com.key"))
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("key file: %v %v", st, err)
	}
	loaded, err := m.Load()
	if err != nil || len(loaded) != 1 || !bytes.Equal(loaded[0].KeyPEM, key) {
		t.Fatalf("Load = %v %v", loaded, err)
	}
	missing, err := (Mirror{Dir: path.Join(t.TempDir(), "none")}).Load()
	if err != nil || missing != nil {
		t.Fatalf("missing mirror: %v %v", missing, err)
	}
}

func TestParseKey(t *testing.T) {
	for key, ok := range map[string]bool{
		"certificates/acme-v02.api.letsencrypt.org-directory/a.example.com/a.example.com.crt": true,
		"certificates/local/wildcard_.x.example.com/wildcard_.x.example.com.key":              true,
		"certificates/acme/a.example.com/a.example.com.json":                                  false,
		"certificates/acme/../../x/x.crt":                                                     false,
		"certificates/acme/A.example.com/A.example.com.crt":                                   false,
		"acme/a/a.crt":                false,
		"certificates/acme/a/b/a.crt": false,
	} {
		if _, _, _, got := parseKey(key); got != ok {
			t.Errorf("%s: %v", key, got)
		}
	}
}
