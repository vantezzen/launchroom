package caddy

import (
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// dnsFields maps each DNS provider's credential value names
// (state.DNSCredentialKeys) to the JSON fields of its caddy-dns module
// (deploy/images/caddy; field names checked against the pinned module
// sources). Every value is rendered as a {file.*} placeholder: each module
// runs its fields through Caddy's replacer at provision time, so secrets
// never appear in the config, the admin API or the config cache.
var dnsFields = map[state.DNSProvider]map[string]string{
	state.DNSCloudflare:   {"token": "api_token"},
	state.DNSHetzner:      {"token": "api_token"},
	state.DNSRoute53:      {"accessKeyId": "access_key_id", "secretAccessKey": "secret_access_key"},
	state.DNSDigitalOcean: {"token": "auth_token"},
	state.DNSPorkbun:      {"apiKey": "api_key", "secretKey": "api_secret_key"},
	state.DNSDesec:        {"token": "token"},
	state.DNSGandi:        {"token": "bearer_token"},
	state.DNSOVH:          {"endpoint": "endpoint", "applicationKey": "application_key", "applicationSecret": "application_secret", "consumerKey": "consumer_key"},
	state.DNSNetcup:       {"customerNumber": "customer_number", "apiKey": "api_key", "apiPassword": "api_password"},
}

// dnsSecretFile is the secret file name of one DNS credential value
// ("token" keeps its historical name "dns-token").
func dnsSecretFile(key string) string { return "dns-" + strings.ToLower(key) }

// dnsSecretFiles are every DNS secret file name any provider uses: stale
// ones are overwritten with nothing when the provider changes.
func dnsSecretFiles() []string {
	seen := map[string]bool{}
	var out []string
	for _, fields := range dnsFields {
		for k := range fields {
			if n := dnsSecretFile(k); !seen[n] {
				seen[n] = true
				out = append(out, n)
			}
		}
	}
	return out
}

// dnsChallenge is the DNS-01 challenge config of the provider.
func dnsChallenge(d *state.DNSChallengeSpec) obj {
	p := obj{"name": string(d.Provider)}
	for key, field := range dnsFields[d.Provider] {
		p[field] = secretFile(dnsSecretFile(key))
	}
	if d.Provider == state.DNSRoute53 {
		// Route 53 is a global service signed in us-east-1; never let the
		// SDK fall back to a profile, the environment or instance metadata.
		p["region"] = "us-east-1"
	}
	return obj{"provider": p}
}
