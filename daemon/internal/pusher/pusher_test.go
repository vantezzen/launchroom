package pusher

import (
	"net/url"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func mustURL(s string) *url.URL {
	u, err := url.Parse(s)
	if err != nil {
		panic(err)
	}
	return u
}

func TestEndpoint(t *testing.T) {
	cp, _ := url.Parse("https://dash.example.com/base")
	for _, tc := range []struct {
		cfg  state.NudgeConfig
		cp   *url.URL
		want string
	}{
		{state.NudgeConfig{Key: "k"}, cp, "wss://dash.example.com/base/app/k?client=launchroomd&flash=false&protocol=7&version=1.0.0"},
		{state.NudgeConfig{Key: "k", URL: "ws://reverb:6001"}, cp, "ws://reverb:6001/app/k?client=launchroomd&flash=false&protocol=7&version=1.0.0"},
		{state.NudgeConfig{Key: "k"}, mustURL("http://127.0.0.1:8000"), "ws://127.0.0.1:8000/app/k?client=launchroomd&flash=false&protocol=7&version=1.0.0"},
	} {
		got, err := Endpoint(&tc.cfg, tc.cp, "1.0.0")
		if err != nil || got != tc.want {
			t.Errorf("Endpoint(%+v) = %q, %v; want %q", tc.cfg, got, err, tc.want)
		}
	}
	if _, err := Endpoint(&state.NudgeConfig{Key: "k"}, nil, "1"); err == nil {
		t.Error("no URL at all accepted")
	}
}
