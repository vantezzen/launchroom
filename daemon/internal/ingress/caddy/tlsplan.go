package caddy

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"path"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/certstore"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// tlsPlan is the certificate setup of one Sync: what to render, the secret
// and mirror files to copy into the container, and the storage status.
type tlsPlan struct {
	tls     TLS
	files   map[string]secret.Secret // container path → content
	redis   *certstore.Redis         // shared storage (also when degraded)
	storage *state.CertStorageStatus
	// notes are secret-free warnings (logged once per change).
	notes []string
}

// fingerprint identifies the file contents (in memory only, never logged
// or persisted): a change forces Caddy to re-read its {file.*} secrets.
func (p *tlsPlan) fingerprint() string {
	names := make([]string, 0, len(p.files))
	for n := range p.files {
		names = append(names, n)
	}
	slices.Sort(names)
	h := sha256.New()
	for _, n := range names {
		_, _ = fmt.Fprintf(h, "%s\x00%d\x00", n, len(p.files[n].Reveal()))
		h.Write([]byte(p.files[n].Reveal()))
	}
	return hex.EncodeToString(h.Sum(nil))
}

// plan opens the sealed ingress credentials, checks the storage against the
// local policy and its reachability, and falls back to the local mirror
// (degraded) or Caddy's defaults when something is missing.
func (m *Manager) plan(ctx context.Context, spec *state.IngressSpec) *tlsPlan {
	p := &tlsPlan{files: map[string]secret.Secret{}}
	for _, n := range append(dnsSecretFiles(), SecretStorageUser, SecretStoragePassword, SecretStorageKey) {
		p.files[path.Join(SecretsDir, n)] = "" // stale secrets are overwritten with nothing
	}
	if spec == nil {
		spec = &state.IngressSpec{}
	}
	if d := spec.DNS; d != nil {
		vals, err := m.openSecret(d.Credentials, state.IngressSecretDNS)
		if err == nil {
			err = state.ValidateDNSCredentials(d.Provider, vals)
		}
		if err != nil {
			p.notes = append(p.notes, "DNS-01 credentials unusable, wildcard routes use per-host certificates: "+err.Error())
		} else {
			p.tls.DNS = d
			for key := range dnsFields[d.Provider] {
				p.files[path.Join(SecretsDir, dnsSecretFile(key))] = vals[key]
			}
		}
	}
	if s := spec.Storage; s == nil {
		p.storage = &state.CertStorageStatus{State: state.StorageLocal}
	} else if err := policy.CheckCertStorageAddress(s.Address, m.o.Policy); err != nil {
		p.notes = append(p.notes, "shared certificate storage refused by local policy: "+err.Error())
		p.storage = &state.CertStorageStatus{State: state.StorageLocal, Message: truncateText(err.Error(), 1024)}
	} else if vals, err := m.openSecret(s.Credentials, state.IngressSecretCertStorage); err != nil {
		p.notes = append(p.notes, "shared certificate storage credentials unusable: "+err.Error())
		p.storage = &state.CertStorageStatus{State: state.StorageLocal, Message: "credentials unusable"}
	} else {
		p.redis = &certstore.Redis{
			Addr: string(s.Address), Username: vals["username"], Password: vals["password"],
			KeyPrefix: string(s.KeyPrefix), EncryptionKey: vals["encryptionKey"], Timeout: 3 * time.Second,
		}
		p.files[path.Join(SecretsDir, SecretStorageUser)] = vals["username"]
		p.files[path.Join(SecretsDir, SecretStoragePassword)] = vals["password"]
		p.files[path.Join(SecretsDir, SecretStorageKey)] = vals["encryptionKey"]
		if err := p.redis.Ping(ctx); err == nil {
			p.tls.Storage = &Storage{Address: string(s.Address), KeyPrefix: string(s.KeyPrefix), HasUsername: vals["username"] != ""}
			p.storage = &state.CertStorageStatus{State: state.StorageShared}
		} else {
			// Degraded (docs/05 §5): Caddy keeps serving from the mirror and a
			// local file storage until the shared storage is back.
			mirrored, _ := m.mirror().Load()
			for _, c := range mirrored {
				f := MirrorFile{Certificate: path.Join(MirrorDir, c.Name+".crt"), Key: path.Join(MirrorDir, c.Name+".key"), Names: c.Leaf.DNSNames}
				p.tls.Mirror = append(p.tls.Mirror, f)
				p.files[f.Certificate] = secret.Secret(c.CertPEM)
				p.files[f.Key] = secret.Secret(c.KeyPEM)
			}
			p.storage = &state.CertStorageStatus{State: state.StorageDegraded, Mirrored: len(mirrored),
				Message: truncateText("shared certificate storage unreachable: "+err.Error(), 1024)}
			p.notes = append(p.notes, p.storage.Message)
		}
	}
	return p
}

func (m *Manager) openSecret(s state.Sealed, purpose state.IngressSecretPurpose) (map[string]secret.Secret, error) {
	if m.o.OpenSecret == nil {
		return nil, errors.New("no sealing key")
	}
	return m.o.OpenSecret(s, purpose)
}

func (m *Manager) mirror() certstore.Mirror {
	return certstore.Mirror{Dir: path.Join(m.o.CacheDir, "certs")}
}

// copyFiles writes the plan's files into the Caddy container (data volume),
// 0600, before its config is loaded.
func (m *Manager) copyFiles(ctx context.Context, containerID string, files map[string]secret.Secret) error {
	ops, ok := m.o.Docker.(docker.SystemOps)
	if !ok {
		return nil // test doubles without archive support
	}
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	dirs := map[string]bool{}
	names := make([]string, 0, len(files))
	for n := range files {
		names = append(names, n)
	}
	slices.Sort(names)
	now := m.o.Now()
	for _, n := range names {
		for d := path.Dir(n); d != "/" && !dirs[d]; d = path.Dir(d) {
			dirs[d] = true
		}
	}
	dirList := make([]string, 0, len(dirs))
	for d := range dirs {
		dirList = append(dirList, d)
	}
	slices.Sort(dirList) // parents first
	for _, d := range dirList {
		if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: d[1:] + "/", Mode: 0o700, ModTime: now}); err != nil {
			return err
		}
	}
	for _, n := range names {
		data := []byte(files[n].Reveal())
		if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: n[1:], Mode: 0o600, Size: int64(len(data)), ModTime: now}); err != nil {
			return err
		}
		if _, err := tw.Write(data); err != nil {
			return err
		}
		clear(data)
	}
	if err := tw.Close(); err != nil {
		return err
	}
	defer clear(buf.Bytes())
	return ops.CopyToContainer(ctx, containerID, "/", &buf)
}

// RunCertificates refreshes certificate statuses and the local mirror every
// interval (and after config loads) until ctx is done.
func (m *Manager) RunCertificates(ctx context.Context, interval time.Duration) {
	if interval <= 0 {
		interval = 2 * time.Minute
	}
	for {
		m.RefreshCertificates(ctx)
		select {
		case <-ctx.Done():
			return
		case <-m.certKick:
		case <-time.After(interval):
		}
	}
}

// RefreshCertificates reads Caddy's issuance log and its certificates once.
func (m *Manager) RefreshCertificates(ctx context.Context) {
	m.mu.Lock()
	routes, t, redis, id, degraded := m.lastRoutes, m.lastTLS, m.redis, m.containerID, m.degraded
	m.mu.Unlock()
	if id == "" {
		return
	}
	ctx, cancel := context.WithTimeout(ctx, time.Minute)
	defer cancel()
	m.readLogs(ctx, id)

	var certs []certstore.Cert
	var err error
	switch {
	case redis != nil && !degraded:
		certs, err = redis.Certificates(ctx, true)
		if err == nil {
			err = m.mirror().Replace(routedCerts(routes, certs, m.o.Now()))
		}
	case degraded:
		certs, err = m.mirror().Load()
	default:
		reader, ok := m.o.Docker.(docker.ArchiveReader)
		if !ok {
			return
		}
		var archive []byte
		archive, err = reader.CopyFromContainer(ctx, id, certstore.FileStoragePath, certstore.MaxArchiveBytes)
		if errors.Is(err, docker.ErrNotFound) {
			err = nil // nothing issued yet
		} else if err == nil {
			certs, err = certstore.FromTar(archive, false)
		}
		clear(archive)
	}
	if err != nil {
		m.o.Log.Warn("could not read certificates", "err", err)
		return
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	m.certs = CertificateStatuses(routes, t, certstore.NewIndex(certs), m.events, m.o.Now())
	if m.storage != nil && redis != nil && !degraded {
		mirrored, _ := m.mirror().Load()
		m.storage.Mirrored = len(mirrored)
	}
}

// routedCerts are the certificates serving routed hosts (what is mirrored).
func routedCerts(routes []Route, certs []certstore.Cert, now time.Time) []certstore.Cert {
	ix := certstore.NewIndex(certs)
	seen := map[*certstore.Cert]bool{}
	var out []certstore.Cert
	for _, r := range routes {
		for _, h := range r.Spec.Hosts {
			if c, _ := ix.Lookup(string(h), now); c != nil && !seen[c] && now.Before(c.Leaf.NotAfter) {
				seen[c] = true
				out = append(out, *c)
			}
		}
	}
	return out
}

// readLogs folds new Caddy log lines into the per-identifier events.
func (m *Manager) readLogs(ctx context.Context, id string) {
	m.mu.Lock()
	since, sameContainer := m.logsSince, m.logsContainer == id
	m.mu.Unlock()
	opts := docker.LogsOptions{Since: since, Tail: -1}
	if !sameContainer || since.IsZero() {
		opts = docker.LogsOptions{Tail: 20000}
	}
	var last time.Time
	events := map[string]certEvent{}
	err := m.o.Docker.StreamLogs(ctx, id, opts, func(l docker.LogLine) error {
		if l.Time.After(last) {
			last = l.Time
		}
		ident, ok, msg, matched := parseCertLog(l.Text)
		if !matched {
			return nil
		}
		ev := events[ident]
		if ok {
			ev.lastOKAt = l.Time
		} else {
			ev.lastErr, ev.lastErrAt = msg, l.Time
		}
		events[ident] = ev
		return nil
	})
	if err != nil {
		m.o.Log.Debug("could not read caddy logs", "err", err)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if !sameContainer {
		m.events = map[string]certEvent{}
		m.logsContainer = id
	}
	for k, ev := range events {
		cur := m.events[k]
		if ev.lastOKAt.After(cur.lastOKAt) {
			cur.lastOKAt = ev.lastOKAt
		}
		if ev.lastErrAt.After(cur.lastErrAt) {
			cur.lastErr, cur.lastErrAt = ev.lastErr, ev.lastErrAt
		}
		m.events[k] = cur
	}
	if last.After(m.logsSince) {
		m.logsSince = last
	}
}

func truncateText(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
