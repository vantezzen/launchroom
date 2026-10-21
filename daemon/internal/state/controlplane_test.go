package state

import (
	"net/url"
	"strings"
	"testing"
)

func TestControlPlaneOrigin(t *testing.T) {
	good := []string{"https://dash.example.com", "https://a.b", "https://dash.example.com:8443", "https://1-2-3-4.sslip.io", "https://xn--bcher-kva.example"}
	bad := []string{
		"http://dash.example.com", "https://localhost", "https://dash.example.com/", "https://dash.example.com/x",
		"https://Dash.example.com", "https://10.0.0.1", "https://dash.example.com:0", "https://dash.example.com:70000",
		"https://user@dash.example.com", "https://dash.example.com?x", "https://-a.example.com", "https://[::1]",
		"https://" + strings.Repeat("a.", 130) + "com", "",
	}
	for _, v := range good {
		if err := ControlPlaneOrigin(v).Validate(); err != nil {
			t.Errorf("%s: %v", v, err)
		}
	}
	for _, v := range bad {
		if err := ControlPlaneOrigin(v).Validate(); err == nil {
			t.Errorf("%s: accepted", v)
		}
	}
}

func TestControlPlaneBaseURL(t *testing.T) {
	cases := map[string]ControlPlaneBaseURL{
		"http://127.0.0.1:8000":        "http://127.0.0.1:8000",
		"https://Dash.Example.com/":    "https://dash.example.com",
		"http://dashboard:8000/base/":  "http://dashboard:8000/base",
		"http://[::1]:8000":            "http://[::1]:8000",
		"https://dash.example.com?x=1": "https://dash.example.com",
		"ftp://dash.example.com":       "",
	}
	for raw, want := range cases {
		u, err := url.Parse(raw)
		if err != nil {
			t.Fatal(err)
		}
		if got := NewControlPlaneBaseURL(u); got != want {
			t.Errorf("%s: %q, want %q", raw, got, want)
		}
	}
	if NewControlPlaneBaseURL(nil) != "" {
		t.Error("nil")
	}
}

func TestOriginOfAndLoopback(t *testing.T) {
	cases := map[string]string{
		"https://Dash.example.com:443/x": "https://dash.example.com",
		"http://dashboard:80":            "http://dashboard",
		"https://dash.example.com:8443":  "https://dash.example.com:8443",
		"ws://x":                         "",
	}
	for raw, want := range cases {
		u, _ := url.Parse(raw)
		if got := OriginOf(u); got != want {
			t.Errorf("%s: %q, want %q", raw, got, want)
		}
	}
	for raw, want := range map[string]bool{
		"http://127.0.0.1:8000": true, "http://localhost:8000": true, "http://[::1]:8000": true, "https://test.localhost": true,
		"https://dash.example.com": false, "http://dashboard:8000": false, "http://10.0.0.5:8000": false,
	} {
		u, _ := url.Parse(raw)
		if got := IsLoopbackHost(u); got != want {
			t.Errorf("%s: %v", raw, got)
		}
	}
}

func TestSystemControlPlaneValidates(t *testing.T) {
	var e errs
	SystemSpec{ControlPlane: ControlPlaneSpec{URL: "https://dash.example.com"}}.validate(&e)
	if err := e.err(); err != nil {
		t.Fatal(err)
	}
	e = errs{}
	SystemSpec{ControlPlane: ControlPlaneSpec{URL: "http://dash.example.com"}}.validate(&e)
	if e.err() == nil {
		t.Fatal("http origin accepted")
	}
}

func TestControlPlaneStatusValidates(t *testing.T) {
	ok := &ControlPlaneStatus{Configured: "http://127.0.0.1:8000", State: ControlPlaneConfigured}
	var e errs
	ok.validate(&e, "host.controlPlane")
	if err := e.err(); err != nil {
		t.Fatal(err)
	}
	for _, s := range []*ControlPlaneStatus{
		{State: "nope"},
		{State: ControlPlaneAdopted, Adopted: "http://x.example"},
		{State: ControlPlanePending, Message: strings.Repeat("x", 513)},
	} {
		e = errs{}
		s.validate(&e, "host.controlPlane")
		if e.err() == nil {
			t.Errorf("%+v accepted", s)
		}
	}
}
