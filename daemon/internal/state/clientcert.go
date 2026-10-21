package state

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/x509"
	"encoding/base64"
	"errors"
	"fmt"
	"regexp"
	"time"
)

// Client certificates for the daemon transport (mTLS, D50, docs/03 §4.3,
// protocol/SIGNING.md §6): an additional layer below the RFC 9421 request
// and X-LR-Signature response signatures, never a replacement.

// Document kinds.
const (
	KindClientCertificateRequest = "ClientCertificateRequest"
	KindClientCertificate        = "ClientCertificate"
)

// Bounds of the certificate fields.
const (
	// MaxCACertificates bounds the trust list (overlapping CA rotation).
	MaxCACertificates = 4
	// maxDERBase64 bounds one base64 DER certificate or public key (8 KiB of base64).
	maxDERBase64 = 8192
)

// Paths of the daemon API that the control-plane edge exempts from requiring
// a client certificate (mode "require"): a new server enrolls and gets its
// first certificate without one. Protocol constants, never configurable.
const (
	DaemonAPIPrefix        = "/daemon/v1/"
	ClientCertificatePath  = "/daemon/v1/client-certificate"
	EnrollPath             = "/daemon/v1/enroll"
	ClientCertHeader       = "X-Lr-Client-Cert"
	EdgeSecretHeader       = "X-Lr-Edge" //nolint:gosec // G101: a header name, not a credential
	ClientCertRequiredBody = `{"error":"client certificate required"}`
)

var reBase64Std = regexp.MustCompile(`^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$`)

// DERBase64 is standard base64 (padded) of DER bytes: a certificate or a
// SubjectPublicKeyInfo.
type DERBase64 string

// ProtocolSchema implements SchemaDescriber.
func (DERBase64) ProtocolSchema() ScalarSchema {
	s := strSchema(reBase64Std, maxDERBase64)
	s.MinLength = 4
	return s
}

// Validate checks the encoding and size.
func (v DERBase64) Validate() error {
	if len(v) < 4 {
		return errors.New("base64 DER value is empty")
	}
	return checkPattern("base64 DER value", string(v), reBase64Std, maxDERBase64)
}

// Bytes decodes the value.
func (v DERBase64) Bytes() ([]byte, error) {
	if err := v.Validate(); err != nil {
		return nil, err
	}
	return base64.StdEncoding.DecodeString(string(v))
}

// Certificate parses the value as one X.509 certificate.
func (v DERBase64) Certificate() (*x509.Certificate, error) {
	der, err := v.Bytes()
	if err != nil {
		return nil, err
	}
	return x509.ParseCertificate(der)
}

// ParseP256PublicKey parses a base64 DER SubjectPublicKeyInfo of an ECDSA P-256 key.
func (v DERBase64) ParseP256PublicKey() (*ecdsa.PublicKey, error) {
	der, err := v.Bytes()
	if err != nil {
		return nil, err
	}
	pub, err := x509.ParsePKIXPublicKey(der)
	if err != nil {
		return nil, fmt.Errorf("public key: %w", err)
	}
	k, ok := pub.(*ecdsa.PublicKey)
	if !ok || k.Curve != elliptic.P256() {
		return nil, errors.New("public key is not an ECDSA P-256 key")
	}
	return k, nil
}

// ClientCertificateRequest is POST /daemon/v1/client-certificate: the daemon
// asks for a client certificate for a key it generated itself.
type ClientCertificateRequest struct {
	TypeMeta
	// PublicKey is the DER SubjectPublicKeyInfo of an ECDSA P-256 key, base64.
	PublicKey DERBase64 `json:"publicKey"`
}

// Validate checks the request.
func (r *ClientCertificateRequest) Validate() error {
	var e errs
	r.check(&e, KindClientCertificateRequest)
	e.check("publicKey", r.PublicKey)
	if r.PublicKey.Validate() == nil {
		if _, err := r.PublicKey.ParseP256PublicKey(); err != nil {
			e.addf("publicKey", "%v", err)
		}
	}
	return e.err()
}

// ClientCertificate is the signed response: the server's client certificate
// and the CA certificates it chains to.
type ClientCertificate struct {
	TypeMeta
	ServerID       ServerID    `json:"serverId"`
	Certificate    DERBase64   `json:"certificate"`
	CACertificates []DERBase64 `json:"caCertificates" jsonschema:"minItems=1,maxItems=4"`
	NotBefore      time.Time   `json:"notBefore"`
	NotAfter       time.Time   `json:"notAfter"`
	// RenewAfter is when the daemon should ask for the next certificate.
	RenewAfter time.Time `json:"renewAfter"`
}

// Validate checks the response.
func (c *ClientCertificate) Validate() error {
	var e errs
	c.check(&e, KindClientCertificate)
	e.check("serverId", c.ServerID)
	e.check("certificate", c.Certificate)
	checkCount(&e, "caCertificates", len(c.CACertificates), 1, MaxCACertificates)
	for i, ca := range c.CACertificates {
		e.check(idx("caCertificates", i), ca)
	}
	unique(&e, "caCertificates", c.CACertificates)
	if c.NotBefore.IsZero() || c.NotAfter.IsZero() || c.RenewAfter.IsZero() {
		e.addf("notAfter", "notBefore, notAfter and renewAfter are required")
	} else if !c.NotAfter.After(c.NotBefore) || c.RenewAfter.Before(c.NotBefore) || c.RenewAfter.After(c.NotAfter) {
		e.addf("renewAfter", "must lie between notBefore and notAfter")
	}
	return e.err()
}

// ClientAuthMode is how the edge treats client certificates on a route.
type ClientAuthMode string

// Client auth modes.
const (
	// ClientAuthVerify checks a presented certificate and forwards its
	// fingerprint; clients without one connect as before.
	ClientAuthVerify ClientAuthMode = "verify"
	// ClientAuthRequire also refuses /daemon/v1/* without a certificate
	// (except enrollment and certificate issuance).
	ClientAuthRequire ClientAuthMode = "require"
)

var clientAuthModes = []string{string(ClientAuthVerify), string(ClientAuthRequire)}

// ProtocolSchema implements SchemaDescriber.
func (ClientAuthMode) ProtocolSchema() ScalarSchema { return enumSchema(clientAuthModes) }

// Validate checks the value.
func (v ClientAuthMode) Validate() error {
	return checkEnum("client auth mode", string(v), clientAuthModes)
}

// RouteClientAuth asks the edge to verify daemon client certificates on a
// route (the dashboard's system routes on the control-plane server only;
// local policy: every upstream must be an LR_ALLOWED_LOCAL_UPSTREAMS address).
type RouteClientAuth struct {
	Mode ClientAuthMode `json:"mode"`
	// CACertificates are the trusted CA certificates (base64 DER).
	CACertificates []DERBase64 `json:"caCertificates" jsonschema:"minItems=1,maxItems=4"`
	// EdgeSecret is a sealed IngressSecret (purpose "client_auth") with
	// "secret": sent to the dashboard in X-Lr-Edge with every proxied request.
	EdgeSecret Sealed `json:"edgeSecret"`
}

func (a RouteClientAuth) validate(e *errs, p string) {
	e.check(p+".mode", a.Mode)
	checkCount(e, p+".caCertificates", len(a.CACertificates), 1, MaxCACertificates)
	for i, ca := range a.CACertificates {
		ip := idx(p+".caCertificates", i)
		e.check(ip, ca)
		if ca.Validate() != nil {
			continue
		}
		cert, err := ca.Certificate()
		if err != nil {
			e.addf(ip, "is not an X.509 certificate")
			continue
		}
		if !cert.IsCA || !cert.BasicConstraintsValid {
			e.addf(ip, "is not a CA certificate")
		}
	}
	unique(e, p+".caCertificates", a.CACertificates)
	e.check(p+".edgeSecret", a.EdgeSecret)
}
