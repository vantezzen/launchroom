package caddy

import (
	"regexp"
	"testing"
)

// The access log filters run inside Caddy with Go's regexp package
// (ReplaceAllString), so they can be checked here (docs/15 §5.1).
func TestAccessLogFilters(t *testing.T) {
	apply := func(pattern, value, in string) string {
		return regexp.MustCompile(pattern).ReplaceAllString(in, value)
	}
	for in, want := range map[string]string{
		"/":                    "/",
		"/cart?id=1&token=abc": "/cart",
		"/docs#x":              "/docs",
		"/a?b\nc":              "/a",
		"/plain/path":          "/plain/path",
	} {
		if got := apply(accessURIQuery, "", in); got != want {
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
		if got := apply(accessRefererHost, "${1}", in); got != want {
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
		if got := apply(accessAgentClass, "${1}${2}", in); got != want {
			t.Errorf("agent %q -> %q, want %q", in, got, want)
		}
	}
}
