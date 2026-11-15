package traffic

// The access line: what the edge Caddy writes for each request (rendered by
// ingress/caddy from these definitions) and what Observe reads. launchroomd
// needs only host, path, status, duration and sizes, never client
// addresses, headers or credentials (docs/15 §1.1); the referrer and user
// agent are added and reduced inside Caddy before a line leaves it
// (docs/15 §5.1).

// Fields Caddy appends to the access line (log_append).
const (
	FieldReferer = "lr_referer"
	FieldAgent   = "lr_agent"
)

// DeletedFields are removed from the line inside Caddy.
var DeletedFields = []string{
	"request>headers", "request>remote_ip", "request>remote_port", "request>client_ip",
	"request>tls", "resp_headers", "user_id",
}

// Reduction is a regexp filter Caddy applies to a field (ReplaceAllString
// with Value, Go's regexp syntax).
type Reduction struct{ Field, Regexp, Value string }

// Reductions reduce fields inside Caddy: the URI loses its query string, the referrer keeps only its
// host and the user agent only a bot keyword or "Mozilla" (anything else
// becomes empty). Go's leftmost-first alternation picks a bot keyword
// before "Mozilla", so crawlers that claim to be Mozilla count as bots
// (classifyAgent).
var Reductions = []Reduction{
	{Field: "request>uri", Regexp: `(?s)[?#].*$`, Value: ""},
	{Field: FieldReferer, Regexp: `(?s)^(?:[A-Za-z][A-Za-z0-9+.-]*://)?(?:[^/?#@]*@)?([^/?#:]*).*$`, Value: "${1}"},
	{Field: FieldAgent, Regexp: `(?is)^(?:.*?(bot|crawl|spider|slurp|archiver|externalhit|headless|lighthouse|monitor|preview|curl|wget|python|httpclient|http-client|okhttp|java/|libwww|scrapy|go-http)|(` + agentBrowserToken + `)|).*$`, Value: "${1}${2}"},
}

const agentBrowserToken = "mozilla"

// accessLine is the subset of an access line Observe reads.
type accessLine struct {
	Request struct {
		Host string `json:"host"`
		URI  string `json:"uri"`
	} `json:"request"`
	Referer  string  `json:"lr_referer"` // FieldReferer
	Agent    string  `json:"lr_agent"`   // FieldAgent
	Status   int     `json:"status"`
	Duration float64 `json:"duration"` // seconds
	Size     int64   `json:"size"`
	Read     int64   `json:"bytes_read"`
}
