package caddy

import (
	"context"
	"encoding/json"
	"net/netip"
	"path"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// The provider object of every DNS-01 provider: the module name, the
// module's own JSON field names, and only {file.*} placeholders.
func TestRenderDNSProviders(t *testing.T) {
	want := map[state.DNSProvider]map[string]string{
		state.DNSCloudflare:   {"api_token": "dns-token"},
		state.DNSHetzner:      {"api_token": "dns-token"},
		state.DNSRoute53:      {"access_key_id": "dns-accesskeyid", "secret_access_key": "dns-secretaccesskey"},
		state.DNSDigitalOcean: {"auth_token": "dns-token"},
		state.DNSPorkbun:      {"api_key": "dns-apikey", "api_secret_key": "dns-secretkey"},
		state.DNSDesec:        {"token": "dns-token"},
		state.DNSGandi:        {"bearer_token": "dns-token"},
		state.DNSOVH: {
			"endpoint": "dns-endpoint", "application_key": "dns-applicationkey",
			"application_secret": "dns-applicationsecret", "consumer_key": "dns-consumerkey",
		},
		state.DNSNetcup: {"customer_number": "dns-customernumber", "api_key": "dns-apikey", "api_password": "dns-apipassword"},
	}
	routes := ResolveRoutes(multiEdgeRoutes(), resolver(map[state.WorkloadID]Endpoint{
		wlA: {Addr: netip.MustParseAddr("10.231.4.5"), Serving: true, Ready: true},
	}))
	for _, p := range allProviders(t) {
		fields, ok := want[p]
		if !ok {
			t.Fatalf("no expectation for %s", p)
		}
		if got := state.DNSCredentialKeys(p); len(got) != len(fields) {
			t.Errorf("%s: %d credential keys, %d module fields", p, len(got), len(fields))
		}
		cfg, err := RenderTLS(routes, TLS{DNS: &state.DNSChallengeSpec{Provider: p, Domains: []state.Hostname{"apps.example.com"}}}, renderOpts)
		if err != nil {
			t.Fatal(err)
		}
		provider := dnsProviderObject(t, cfg)
		if provider["name"] != string(p) {
			t.Errorf("%s: name = %v", p, provider["name"])
		}
		for field, file := range fields {
			if got := provider[field]; got != "{file./data/launchroom/secrets/"+file+"}" {
				t.Errorf("%s: %s = %v", p, field, got)
			}
		}
		extra := len(provider) - 1 - len(fields)
		if p == state.DNSRoute53 {
			if provider["region"] != "us-east-1" {
				t.Errorf("route53 region = %v", provider["region"])
			}
			extra--
		}
		if extra != 0 {
			t.Errorf("%s: unexpected fields in %v", p, provider)
		}
	}
}

func allProviders(t *testing.T) []state.DNSProvider {
	t.Helper()
	var out []state.DNSProvider
	for _, v := range state.DNSProvider("").ProtocolSchema().Enum {
		out = append(out, state.DNSProvider(v))
	}
	if len(out) < 9 {
		t.Fatalf("providers = %v", out)
	}
	return out
}

// dnsProviderObject finds the DNS challenge provider in a rendered config.
func dnsProviderObject(t *testing.T, cfg []byte) map[string]any {
	t.Helper()
	var doc struct {
		Apps struct {
			TLS struct {
				Automation struct {
					Policies []struct {
						Issuers []struct {
							Challenges struct {
								DNS struct {
									Provider map[string]any `json:"provider"`
								} `json:"dns"`
							} `json:"challenges"`
						} `json:"issuers"`
					} `json:"policies"`
				} `json:"automation"`
			} `json:"tls"`
		} `json:"apps"`
	}
	if err := json.Unmarshal(cfg, &doc); err != nil {
		t.Fatal(err)
	}
	for _, p := range doc.Apps.TLS.Automation.Policies {
		for _, iss := range p.Issuers {
			if iss.Challenges.DNS.Provider != nil {
				return iss.Challenges.DNS.Provider
			}
		}
	}
	t.Fatalf("no DNS challenge in:\n%s", cfg)
	return nil
}

func TestValidateDNSCredentials(t *testing.T) {
	ok := map[state.DNSProvider]map[string]secret.Secret{
		state.DNSRoute53: {"accessKeyId": "AKIA", "secretAccessKey": "s"},
		state.DNSOVH:     {"endpoint": "ovh-eu", "applicationKey": "a", "applicationSecret": "b", "consumerKey": "c"},
		state.DNSNetcup:  {"customerNumber": "12345", "apiKey": "k", "apiPassword": "p"},
		state.DNSPorkbun: {"apiKey": "pk1_x", "secretKey": "sk1_y"},
		state.DNSGandi:   {"token": "t"},
	}
	for p, vals := range ok {
		if err := state.ValidateDNSCredentials(p, vals); err != nil {
			t.Errorf("%s: %v", p, err)
		}
	}
	bad := []struct {
		p    state.DNSProvider
		vals map[string]secret.Secret
	}{
		{state.DNSRoute53, map[string]secret.Secret{"accessKeyId": "AKIA"}},          // missing secret key
		{state.DNSCloudflare, map[string]secret.Secret{"token": "t", "apiKey": "x"}}, // another provider's value
		{state.DNSOVH, map[string]secret.Secret{"endpoint": "https://evil.example", "applicationKey": "a", "applicationSecret": "b", "consumerKey": "c"}},
		{state.DNSNetcup, map[string]secret.Secret{"customerNumber": "12a", "apiKey": "k", "apiPassword": "p"}},
		{"bind", map[string]secret.Secret{"token": "t"}},
	}
	for _, b := range bad {
		err := state.ValidateDNSCredentials(b.p, b.vals)
		if err == nil {
			t.Errorf("%s %v: accepted", b.p, b.vals)
			continue
		}
		for _, v := range b.vals {
			if len(v.Reveal()) > 3 && strings.Contains(err.Error(), v.Reveal()) {
				t.Errorf("error leaks a value: %v", err)
			}
		}
	}
}

// Credentials that don't fit the provider never reach Caddy: wildcard
// routes fall back to per-host certificates, and every DNS secret file is
// written empty.
func TestPlanRefusesMismatchedDNSCredentials(t *testing.T) {
	spec := &state.IngressSpec{DNS: &state.DNSChallengeSpec{Provider: state.DNSRoute53, Domains: []state.Hostname{"apps.example.com"}}}
	m, _, _ := managerWithSpec(t, spec, policy.DefaultConfig()) // opens {"token": …}
	p := m.plan(context.Background(), spec)
	if p.tls.DNS != nil || len(p.notes) == 0 || !strings.Contains(p.notes[0], "DNS-01 credentials unusable") {
		t.Fatalf("plan = %+v, notes %v", p.tls, p.notes)
	}
	for _, n := range dnsSecretFiles() {
		if v := p.files[path.Join(SecretsDir, n)]; !v.IsZero() {
			t.Errorf("%s written", n)
		}
	}
	if !slices.Contains(dnsSecretFiles(), "dns-token") {
		t.Error("dns-token is not cleaned up")
	}
}
