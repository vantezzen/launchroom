package caddy

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"math/big"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func testCADER(t *testing.T) state.DERBase64 {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "launchroom server CA"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true,
		KeyUsage: x509.KeyUsageCertSign}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return state.DERBase64(base64.StdEncoding.EncodeToString(der))
}

func dashRoute(t *testing.T, mode state.ClientAuthMode, upstream string) Route {
	return Route{
		Spec: state.RouteSpec{
			ID: "rt_0000000000000000000000DASH", Hosts: []state.Hostname{"dash.example.com", "old.example.com"}, TLS: state.TLSACME,
			Upstreams:  []state.RouteUpstream{{Address: state.AddrPort(upstream)}},
			ClientAuth: &state.RouteClientAuth{Mode: mode, CACertificates: []state.DERBase64{testCADER(t)}, EdgeSecret: state.Sealed{Sealed: "x"}},
		},
		Dial:           []string{upstream},
		EdgeSecretFile: EdgeSecretFile("rt_0000000000000000000000DASH"),
	}
}

func renderMap(t *testing.T, routes []Route, o Options) map[string]any {
	t.Helper()
	data, err := Render(routes, o)
	if err != nil {
		t.Fatal(err)
	}
	var m map[string]any
	if err := json.Unmarshal(data, &m); err != nil {
		t.Fatal(err)
	}
	return m
}

func httpsServer(m map[string]any) map[string]any {
	return m["apps"].(map[string]any)["http"].(map[string]any)["servers"].(map[string]any)[serverHTTPS].(map[string]any)
}

func TestClientAuthRendersPoliciesHeadersAndRequire(t *testing.T) {
	o := renderOpts
	o.LocalUpstreams = []string{"127.0.0.1:8000"}
	r := dashRoute(t, state.ClientAuthRequire, "127.0.0.1:8000")
	data, err := Render([]Route{r, {Spec: routeSpecs()[0], Dial: []string{"10.0.0.1:3000"}}}, o)
	if err != nil {
		t.Fatal(err)
	}
	s := string(data)
	for _, want := range []string{
		`"verify_if_given"`, `"provider": "inline"`, `"trusted_ca_certs"`, `"sni"`,
		`"lr_client_cert": "{http.request.tls.client.fingerprint}"`, `"lr_client_cert": ""`, emptyFingerprint,
		`"X-Lr-Client-Cert": [`, `"{http.vars.lr_client_cert}"`,
		`"X-Lr-Edge": [`, `"{file.` + SecretsDir + `/client-auth-rt_0000000000000000000000dash}"`,
		`"/daemon/v1/*"`, `"/daemon/v1/enroll"`, `"/daemon/v1/client-certificate"`, `"status_code": 401`,
	} {
		if !strings.Contains(s, want) {
			t.Errorf("config lacks %s", want)
		}
	}
	pol := httpsServer(renderMap(t, []Route{r}, o))["tls_connection_policies"].([]any)
	if len(pol) != 2 || len(pol[1].(map[string]any)) != 0 {
		t.Fatalf("want the route's policy then a catch-all, got %v", pol)
	}
	sni := pol[0].(map[string]any)["match"].(map[string]any)["sni"].([]any)
	if len(sni) != 2 || sni[0] != "dash.example.com" || sni[1] != "old.example.com" {
		t.Errorf("sni = %v", sni)
	}
}

func TestClientAuthVerifyHasNoRequireRoute(t *testing.T) {
	o := renderOpts
	o.LocalUpstreams = []string{"127.0.0.1:8000"}
	data, err := Render([]Route{dashRoute(t, state.ClientAuthVerify, "127.0.0.1:8000")}, o)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(data), `"status_code": 401`) {
		t.Error("verify mode must not refuse requests without a certificate")
	}
	if !strings.Contains(string(data), "verify_if_given") {
		t.Error("no client authentication policy")
	}
}

func TestClientAuthRefusedByLocalPolicy(t *testing.T) {
	cases := map[string]func(o *Options, r *Route){
		"upstream not allowed locally": func(o *Options, _ *Route) { o.LocalUpstreams = []string{"127.0.0.1:9000"} },
		"no local upstreams":           func(o *Options, _ *Route) { o.LocalUpstreams = nil },
		"edge secret unusable":         func(_ *Options, r *Route) { r.EdgeSecretFile = "" },
		"workload upstream": func(_ *Options, r *Route) {
			r.Spec.Upstreams = []state.RouteUpstream{{Workload: wlA, Port: 3000}}
		},
		"one of two upstreams not local": func(_ *Options, r *Route) {
			r.Spec.Upstreams = append(r.Spec.Upstreams, state.RouteUpstream{Address: "100.64.0.9:31002"})
		},
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			o := renderOpts
			o.LocalUpstreams = []string{"127.0.0.1:8000"}
			r := dashRoute(t, state.ClientAuthRequire, "127.0.0.1:8000")
			mutate(&o, &r)
			data, err := Render([]Route{r}, o)
			if err != nil {
				t.Fatal(err)
			}
			s := string(data)
			if !strings.Contains(s, pageRefusedClientAuth.plain[:30]) || strings.Contains(s, "reverse_proxy") {
				t.Error("refused route is not answered with 403 only")
			}
			if strings.Contains(s, "tls_connection_policies") || strings.Contains(s, "X-Lr-Edge") {
				t.Error("refused route still configures client authentication")
			}
			if got := RefusedClientAuth([]Route{r}, o); len(got) != 1 {
				t.Errorf("RefusedClientAuth = %v", got)
			}
		})
	}
}

func TestNoClientAuthKeepsConfigUnchanged(t *testing.T) {
	routes := []Route{{Spec: routeSpecs()[0], Dial: []string{"10.0.0.1:3000"}}}
	data, err := Render(routes, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	for _, unwanted := range []string{"tls_connection_policies", "lr_client_cert", "X-Lr-"} {
		if strings.Contains(string(data), unwanted) {
			t.Errorf("config without clientAuth contains %s", unwanted)
		}
	}
}
