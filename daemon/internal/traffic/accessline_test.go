package traffic

import (
	"reflect"
	"regexp"
	"strings"
	"testing"
)

// reduce applies the reduction of field the way Caddy does.
func reduce(t *testing.T, field, in string) string {
	t.Helper()
	for _, r := range Reductions {
		if r.Field == field {
			return regexp.MustCompile(r.Regexp).ReplaceAllString(in, r.Value)
		}
	}
	t.Fatalf("no reduction of %s", field)
	return ""
}

// The access log filters run inside Caddy with Go's regexp package
// (ReplaceAllString), so they can be checked here (docs/15 §5.1).
func TestAccessLineReductions(t *testing.T) {
	for in, want := range map[string]string{
		"/":                    "/",
		"/cart?id=1&token=abc": "/cart",
		"/docs#x":              "/docs",
		"/a?b\nc":              "/a",
		"/plain/path":          "/plain/path",
	} {
		if got := reduce(t, "request>uri", in); got != want {
			t.Errorf("uri %q -> %q, want %q", in, got, want)
		}
	}
	for in, want := range map[string]string{
		"https://news.ycombinator.com/item?id=1": "news.ycombinator.com",
		"https://user:pw@www.google.com:443/q":   "www.google.com",
		"android-app://com.google.android.gm/":   "com.google.android.gm",
		"":                                       "",
		"not a url":                              "not a url",
	} {
		if got := reduce(t, FieldReferer, in); got != want {
			t.Errorf("referer %q -> %q, want %q", in, got, want)
		}
	}
	for in, want := range map[string]string{
		"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15": "Mozilla",
		"Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)":             "bot",
		"Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0 Safari/537.36":                   "Headless",
		"curl/8.5.0":              "curl",
		"python-requests/2.31":    "python",
		"Go-http-client/1.1":      "Go-http",
		"facebookexternalhit/1.1": "externalhit",
		"SomethingCustom/1.0":     "",
		"":                        "",
	} {
		if got := reduce(t, FieldAgent, in); got != want {
			t.Errorf("agent %q -> %q, want %q", in, got, want)
		}
	}
}

// What the reductions leave is what the decoder classifies: a browser's
// token is a browser, a crawler's a bot, the rest other.
func TestAccessLineAgentClasses(t *testing.T) {
	for in, want := range map[string]int{
		"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15": agentBrowser,
		"Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)":             agentBot,
		"SomethingCustom/1.0": agentOther,
	} {
		if got := classifyAgent(reduce(t, FieldAgent, in)); got != want {
			t.Errorf("%q: class %d, want %d", in, got, want)
		}
	}
	tags := map[string]bool{}
	ty := reflect.TypeFor[accessLine]()
	for i := range ty.NumField() {
		name, _, _ := strings.Cut(ty.Field(i).Tag.Get("json"), ",")
		tags[name] = true
	}
	if !tags[FieldReferer] || !tags[FieldAgent] {
		t.Fatalf("accessLine does not read %s and %s", FieldReferer, FieldAgent)
	}
}
