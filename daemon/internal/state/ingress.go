package state

import (
	"encoding/json/v2"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

// ---- NodeState.ingress: edge-wide certificate setup (docs/05 §4–5) ----

// IngressSpec configures how the edge's Caddy stores and obtains
// certificates. Omitted on servers without the edge role and on edges that
// need nothing beyond Caddy's defaults (local file storage, HTTP-01).
type IngressSpec struct {
	// Storage is certificate storage shared by every edge (Redis protocol,
	// Valkey on the control plane), so several edges coordinate ACME as one
	// client. Omitted: Caddy's local file storage (single edge).
	Storage *CertStorageSpec `json:"storage,omitzero" jsonschema:"nullable"`
	// DNS enables DNS-01 wildcard certificates for routes with tls "wildcard".
	// Omitted: such routes get per-host certificates (HTTP-01).
	DNS *DNSChallengeSpec `json:"dns,omitzero" jsonschema:"nullable"`
}

// CertStorageSpec is shared certificate storage (docs/05 §5). launchroomd
// mirrors every certificate it routes to data/caddy/certs and serves those
// files when the storage is unreachable at Caddy start.
type CertStorageSpec struct {
	// Address of the Redis-protocol server. Local policy: the mesh range or
	// an address in the daemon's LR_ALLOWED_LOCAL_UPSTREAMS.
	Address AddrPort `json:"address"`
	// KeyPrefix namespaces Caddy's keys (e.g. "lr-caddy").
	KeyPrefix StorageKeyPrefix `json:"keyPrefix"`
	// Credentials is a sealed IngressSecret (purpose "cert_storage") with
	// "password", "encryptionKey" (≥ 32 bytes; values are AES-256-GCM
	// encrypted in the storage) and optionally "username".
	Credentials Sealed `json:"credentials"`
}

// DNSChallengeSpec is a DNS provider for DNS-01 challenges.
type DNSChallengeSpec struct {
	Provider DNSProvider `json:"provider"`
	// Domains are the platform domains in the provider's zone(s). Wildcard
	// certificates are only requested for names at or below one of them.
	Domains []Hostname `json:"domains" jsonschema:"minItems=1,maxItems=16"`
	// Credentials is a sealed IngressSecret (purpose "dns") with the
	// provider's values (DNSCredentialKeys): "token" for Cloudflare (Zone.DNS
	// edit, scoped to the zone), Hetzner (Cloud API token), DigitalOcean,
	// deSEC and Gandi (personal access token); "accessKeyId" +
	// "secretAccessKey" for Route 53; "apiKey" + "secretKey" for Porkbun;
	// "endpoint" + "applicationKey" + "applicationSecret" + "consumerKey" for
	// OVHcloud; "customerNumber" + "apiKey" + "apiPassword" for netcup.
	Credentials Sealed `json:"credentials"`
}

func (s *IngressSpec) validate(e *errs) {
	if s.Storage != nil {
		e.check("ingress.storage.address", s.Storage.Address)
		e.check("ingress.storage.keyPrefix", s.Storage.KeyPrefix)
		e.check("ingress.storage.credentials", s.Storage.Credentials)
	}
	if s.DNS != nil {
		e.check("ingress.dns.provider", s.DNS.Provider)
		checkCount(e, "ingress.dns.domains", len(s.DNS.Domains), 1, 16)
		for i, d := range s.DNS.Domains {
			e.check(idx("ingress.dns.domains", i), d)
		}
		unique(e, "ingress.dns.domains", s.DNS.Domains)
		e.check("ingress.dns.credentials", s.DNS.Credentials)
	}
}

// WildcardFor returns the wildcard certificate name "*.<parent>" that serves
// host when the DNS provider covers its parent, else "".
func (d *DNSChallengeSpec) WildcardFor(host Hostname) string {
	if d == nil {
		return ""
	}
	h := string(host)
	dot := strings.IndexByte(h, '.')
	if dot < 0 {
		return ""
	}
	parent := h[dot+1:]
	if strings.Count(parent, ".") < 1 { // never *.tld
		return ""
	}
	for _, dom := range d.Domains {
		if parent == string(dom) || strings.HasSuffix(parent, "."+string(dom)) {
			return "*." + parent
		}
	}
	return ""
}

var reStorageKeyPrefix = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,62}$`)

// StorageKeyPrefix namespaces keys in shared certificate storage.
type StorageKeyPrefix string

// ProtocolSchema implements SchemaDescriber.
func (StorageKeyPrefix) ProtocolSchema() ScalarSchema { return strSchema(reStorageKeyPrefix, 0) }

// Validate checks the grammar.
func (v StorageKeyPrefix) Validate() error {
	return checkPattern("storage key prefix", string(v), reStorageKeyPrefix, 0)
}

// DNSProvider is a DNS-01 provider compiled into launchroom's Caddy image
// (deploy/images/caddy, docs/05 §5, D47).
type DNSProvider string

// DNS providers (docs/05 §5: Cloudflare first, Hetzner second; D47 the rest).
const (
	DNSCloudflare   DNSProvider = "cloudflare"
	DNSHetzner      DNSProvider = "hetzner"
	DNSRoute53      DNSProvider = "route53"
	DNSDigitalOcean DNSProvider = "digitalocean"
	DNSPorkbun      DNSProvider = "porkbun"
	DNSDesec        DNSProvider = "desec"
	DNSGandi        DNSProvider = "gandi"
	DNSOVH          DNSProvider = "ovh"
	DNSNetcup       DNSProvider = "netcup"
)

var dnsProviders = []string{
	string(DNSCloudflare), string(DNSHetzner), string(DNSRoute53), string(DNSDigitalOcean), string(DNSPorkbun),
	string(DNSDesec), string(DNSGandi), string(DNSOVH), string(DNSNetcup),
}

// ProtocolSchema implements SchemaDescriber.
func (DNSProvider) ProtocolSchema() ScalarSchema { return enumSchema(dnsProviders) }

// Validate checks the value.
func (v DNSProvider) Validate() error { return checkEnum("dns provider", string(v), dnsProviders) }

// OVHEndpoints are the OVHcloud API regions the ovh module accepts as "endpoint".
var OVHEndpoints = []string{"ovh-eu", "ovh-ca", "ovh-us"}

// dnsCredentialKeys are the value names of a provider's sealed DNS
// credentials (IngressSecret purpose "dns"). All are required.
var dnsCredentialKeys = map[DNSProvider][]string{
	DNSCloudflare:   {"token"},
	DNSHetzner:      {"token"},
	DNSRoute53:      {"accessKeyId", "secretAccessKey"},
	DNSDigitalOcean: {"token"},
	DNSPorkbun:      {"apiKey", "secretKey"},
	DNSDesec:        {"token"},
	DNSGandi:        {"token"},
	DNSOVH:          {"endpoint", "applicationKey", "applicationSecret", "consumerKey"},
	DNSNetcup:       {"customerNumber", "apiKey", "apiPassword"},
}

// DNSCredentialKeys returns the value names a provider's credentials hold.
func DNSCredentialKeys(p DNSProvider) []string { return slices.Clone(dnsCredentialKeys[p]) }

// allDNSCredentialKeys is every value name any provider uses (sorted).
func allDNSCredentialKeys() []string {
	var out []string
	for _, keys := range dnsCredentialKeys {
		for _, k := range keys {
			if !slices.Contains(out, k) {
				out = append(out, k)
			}
		}
	}
	slices.Sort(out)
	return out
}

var reNetcupCustomer = regexp.MustCompile(`^[0-9]{1,16}$`)

// ValidateDNSCredentials checks opened DNS credentials against the
// provider: exactly its value names, and the grammar of the non-secret ones
// (they are rendered into the Caddy config as literals). Errors never
// contain values.
func ValidateDNSCredentials(p DNSProvider, vals map[string]secret.Secret) error {
	keys, ok := dnsCredentialKeys[p]
	if !ok {
		return checkEnum("dns provider", string(p), dnsProviders)
	}
	var e errs
	for _, k := range keys {
		if vals[k].IsZero() {
			e.addf("values."+k, "is required for %s", p)
		}
	}
	names := make([]string, 0, len(vals))
	for k := range vals {
		names = append(names, k)
	}
	slices.Sort(names)
	for _, k := range names {
		if !slices.Contains(keys, k) {
			e.addf("values", "unknown value %q for %s", truncate(k), p)
		}
	}
	if p == DNSOVH && !vals["endpoint"].IsZero() && !slices.Contains(OVHEndpoints, vals["endpoint"].Reveal()) {
		e.addf("values.endpoint", "must be one of %s", strings.Join(OVHEndpoints, ", "))
	}
	if p == DNSNetcup && !vals["customerNumber"].IsZero() && !reNetcupCustomer.MatchString(vals["customerNumber"].Reveal()) {
		e.addf("values.customerNumber", "must be the netcup customer number (digits)")
	}
	return e.err()
}

// LoadBalancing is a route's upstream selection policy.
type LoadBalancing string

// Load-balancing policies.
const (
	// LBRoundRobin spreads requests over every ready upstream (default).
	LBRoundRobin LoadBalancing = "round_robin"
	// LBPreferLocal sends requests only to this server's ready workloads and
	// uses mesh upstreams only while none is ready (edges that run replicas).
	LBPreferLocal LoadBalancing = "prefer_local"
)

var loadBalancings = []string{string(LBRoundRobin), string(LBPreferLocal)}

// ProtocolSchema implements SchemaDescriber.
func (LoadBalancing) ProtocolSchema() ScalarSchema { return enumSchema(loadBalancings) }

// Validate checks the value.
func (v LoadBalancing) Validate() error {
	return checkEnum("load balancing", string(v), loadBalancings)
}

// ---- Sealed ingress credentials ----

// IngressSecretPurpose binds an IngressSecret to one field.
type IngressSecretPurpose string

// Ingress secret purposes.
const (
	IngressSecretDNS         IngressSecretPurpose = "dns"
	IngressSecretCertStorage IngressSecretPurpose = "cert_storage"
	// IngressSecretClientAuth is a route's clientAuth.edgeSecret (value "secret", D50).
	IngressSecretClientAuth IngressSecretPurpose = "client_auth"
)

var ingressSecretPurposes = []string{string(IngressSecretDNS), string(IngressSecretCertStorage), string(IngressSecretClientAuth)}

// ProtocolSchema implements SchemaDescriber.
func (IngressSecretPurpose) ProtocolSchema() ScalarSchema { return enumSchema(ingressSecretPurposes) }

// Validate checks the value.
func (v IngressSecretPurpose) Validate() error {
	return checkEnum("ingress secret purpose", string(v), ingressSecretPurposes)
}

// Allowed IngressSecret value names per purpose (required ones first).
var ingressSecretKeys = map[IngressSecretPurpose]struct{ required, optional []string }{
	// Per provider: ValidateDNSCredentials (the purpose allows every provider's names).
	IngressSecretDNS:         {optional: allDNSCredentialKeys()},
	IngressSecretCertStorage: {required: []string{"password", "encryptionKey"}, optional: []string{"username"}},
	IngressSecretClientAuth:  {required: []string{"secret"}},
}

// IngressSecret is the plaintext of NodeState.ingress.*.credentials:
// libsodium crypto_box_seal of this JSON object to the daemon's X25519 key.
// ServerID and Purpose bind it to one field on one server (no re-targeting);
// the dashboard seals it only into edge servers' NodeStates.
type IngressSecret struct {
	ServerID ServerID             `json:"serverId"`
	Purpose  IngressSecretPurpose `json:"purpose"`
	// Values: the provider's credentials (dns, DNSCredentialKeys); "password", "encryptionKey", optional "username" (cert_storage).
	Values map[string]secret.Secret `json:"values"`
}

type ingressSecretWire struct {
	ServerID ServerID             `json:"serverId"`
	Purpose  IngressSecretPurpose `json:"purpose"`
	Values   map[string]string    `json:"values"`
}

// MarshalJSON reveals the values deliberately: only used to produce plaintext that is sealed immediately.
func (s IngressSecret) MarshalJSON() ([]byte, error) {
	v := make(map[string]string, len(s.Values))
	for k, x := range s.Values {
		v[k] = x.Reveal()
	}
	return json.Marshal(ingressSecretWire{ServerID: s.ServerID, Purpose: s.Purpose, Values: v}, json.Deterministic(true))
}

// UnmarshalJSON strictly decodes the wire form.
func (s *IngressSecret) UnmarshalJSON(data []byte) error {
	var w ingressSecretWire
	if err := json.Unmarshal(data, &w, strictOptions()); err != nil {
		return err
	}
	v := make(map[string]secret.Secret, len(w.Values))
	for k, x := range w.Values {
		v[k] = secret.Secret(x)
	}
	*s = IngressSecret{ServerID: w.ServerID, Purpose: w.Purpose, Values: v}
	return nil
}

// Validate checks ids, names and value sizes. Errors never contain values.
func (s *IngressSecret) Validate() error {
	var e errs
	e.check("serverId", s.ServerID)
	e.check("purpose", s.Purpose)
	keys, ok := ingressSecretKeys[s.Purpose]
	if !ok {
		return e.err()
	}
	if len(s.Values) == 0 {
		e.addf("values", "must not be empty")
	}
	for _, k := range keys.required {
		if _, ok := s.Values[k]; !ok {
			e.addf("values."+k, "is required")
		}
	}
	names := make([]string, 0, len(s.Values))
	for k := range s.Values {
		names = append(names, k)
	}
	slices.Sort(names)
	for _, k := range names {
		if !slices.Contains(keys.required, k) && !slices.Contains(keys.optional, k) {
			e.addf("values", "unknown value %q for purpose %s", truncate(k), s.Purpose)
			continue
		}
		v := s.Values[k].Reveal()
		if v == "" || len(v) > 4096 || strings.ContainsAny(v, "\x00\r\n") {
			e.addf("values."+k, "must be 1-4096 bytes without NUL or line breaks")
		}
		if k == "encryptionKey" && len(v) < 32 {
			e.addf("values."+k, "must be at least 32 bytes")
		}
	}
	return e.err()
}

// ---- Report: certificate state (docs/05 §5) ----

// CertState is the certificate state of one routed host.
type CertState string

// Certificate states.
const (
	// CertIssued: a certificate covering the host is stored and not expired.
	CertIssued CertState = "issued"
	// CertPending: no certificate yet and no failure seen (on-demand hosts
	// get theirs on the first request; managed ones are being obtained).
	CertPending CertState = "pending"
	// CertFailed: the last attempt to obtain one failed and none is usable.
	CertFailed CertState = "failed"
)

var certStates = []string{string(CertIssued), string(CertPending), string(CertFailed)}

// ProtocolSchema implements SchemaDescriber.
func (CertState) ProtocolSchema() ScalarSchema { return enumSchema(certStates) }

// Validate checks the value.
func (v CertState) Validate() error { return checkEnum("certificate state", string(v), certStates) }

// CertificateStatus is what the edge knows about one routed host's certificate.
type CertificateStatus struct {
	Host  Hostname  `json:"host"`
	State CertState `json:"state"`
	// Wildcard is true when the host is served by a "*.<parent>" certificate.
	Wildcard bool `json:"wildcard,omitzero"`
	// Issuer is the certificate's issuer (organization and common name), e.g. "Let's Encrypt R12".
	Issuer    string     `json:"issuer,omitzero" jsonschema:"maxLength=256"`
	NotBefore *time.Time `json:"notBefore,omitzero" jsonschema:"nullable"`
	NotAfter  *time.Time `json:"notAfter,omitzero" jsonschema:"nullable"`
	// LastError is Caddy's last issuance or renewal error for the host
	// (kept while a newer success has not been seen).
	LastError   string     `json:"lastError,omitzero" jsonschema:"maxLength=1024"`
	LastErrorAt *time.Time `json:"lastErrorAt,omitzero" jsonschema:"nullable"`
}

// CertStorageState is where the edge's Caddy keeps certificates.
type CertStorageState string

// Certificate storage states.
const (
	// StorageLocal: Caddy's local file storage (no shared storage configured).
	StorageLocal CertStorageState = "local"
	// StorageShared: the shared storage is in use and reachable.
	StorageShared CertStorageState = "shared"
	// StorageDegraded: the shared storage is unreachable; Caddy serves the
	// local mirror and a local file storage until it is back.
	StorageDegraded CertStorageState = "degraded"
)

var certStorageStates = []string{string(StorageLocal), string(StorageShared), string(StorageDegraded)}

// ProtocolSchema implements SchemaDescriber.
func (CertStorageState) ProtocolSchema() ScalarSchema { return enumSchema(certStorageStates) }

// Validate checks the value.
func (v CertStorageState) Validate() error {
	return checkEnum("certificate storage state", string(v), certStorageStates)
}

// CertStorageStatus reports the certificate storage of an edge.
type CertStorageStatus struct {
	State CertStorageState `json:"state"`
	// Mirrored is the number of certificates in the local mirror.
	Mirrored int `json:"mirrored" jsonschema:"minimum=0"`
	// Message is a short, secret-free explanation (e.g. why storage is degraded).
	Message string `json:"message,omitzero" jsonschema:"maxLength=1024"`
}

func (s *IngressStatus) validateCerts(e *errs) {
	checkCount(e, "ingress.certificates", len(s.Certificates), 0, maxCertificates)
	hosts := make([]Hostname, 0, len(s.Certificates))
	for i, c := range s.Certificates {
		p := idx("ingress.certificates", i)
		e.check(p+".host", c.Host)
		e.check(p+".state", c.State)
		checkText(e, p+".issuer", c.Issuer, 256)
		checkText(e, p+".lastError", c.LastError, 1024)
		hosts = append(hosts, c.Host)
	}
	unique(e, "ingress.certificates.host", hosts)
	if s.Storage != nil {
		e.check("ingress.storage.state", s.Storage.State)
		if s.Storage.Mirrored < 0 {
			e.addf("ingress.storage.mirrored", "must be >= 0")
		}
		checkText(e, "ingress.storage.message", s.Storage.Message, 1024)
	}
}

// maxCertificates bounds Report.ingress.certificates.
const maxCertificates = 4096
