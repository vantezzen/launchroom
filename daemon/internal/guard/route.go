package guard

import (
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
)

// op is one allow-listed Engine API operation.
type op int

const (
	opPing op = iota + 1
	opVersion
	opInfo
	opDiskUsage
	opContainerList
	opContainerCreate
	opContainerInspect
	opContainerStart
	opContainerStop
	opContainerPause
	opContainerUnpause
	opContainerWait
	opContainerRemove
	opContainerLogs
	opContainerStats
	opArchiveHead
	opArchiveGet
	opArchivePut
	opExecCreate
	opExecStart
	opExecResize
	opExecInspect
	opNetworkList
	opNetworkInspect
	opNetworkCreate
	opNetworkRemove
	opNetworkConnect
	opNetworkDisconnect
	opVolumeList
	opVolumeInspect
	opVolumeCreate
	opVolumeRemove
	opImageInspect
	opImageCreate
	opImageRemove
)

// Grammars of path parameters. Docker names and ids, exec ids, image refs.
var (
	reObject  = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$`)
	reExecID  = regexp.MustCompile(`^[a-f0-9]{64}$`)
	reImage   = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9._:/@-]{0,511}$`)
	reVersion = regexp.MustCompile(`^/v1\.([4-9][0-9])(/.*)$`)
	// Container paths of the archive endpoints.
	reCtrPath = regexp.MustCompile(`^/[A-Za-z0-9._/@+=-]{0,999}$`)
)

// qrule validates one query value.
type qrule struct {
	multi bool
	check func(string) error
}

func qBool() qrule {
	return qrule{check: func(v string) error {
		switch v {
		case "0", "1", "true", "false", "True", "False":
			return nil
		}
		return fmt.Errorf("%q is not a boolean", v)
	}}
}

// qFalse allows a boolean that must be false (force flags that are never set).
func qFalse() qrule {
	return qrule{check: func(v string) error {
		if v == "0" || v == "false" || v == "False" {
			return nil
		}
		return errors.New("must be false")
	}}
}

func qInt(lo, hi int64) qrule {
	return qrule{check: func(v string) error {
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil || n < lo || n > hi {
			return fmt.Errorf("%q is not an integer in [%d, %d]", truncate(v, 32), lo, hi)
		}
		return nil
	}}
}

func qEnum(values ...string) qrule {
	return qrule{check: func(v string) error {
		for _, x := range values {
			if v == x {
				return nil
			}
		}
		return fmt.Errorf("%q is not one of %s", truncate(v, 32), strings.Join(values, ", "))
	}}
}

func qRe(re *regexp.Regexp) qrule {
	return qrule{check: func(v string) error {
		if !re.MatchString(v) {
			return fmt.Errorf("%q does not match %s", truncate(v, 64), re)
		}
		return nil
	}}
}

// qAny allows any value of bounded length (checked by the op handler).
func qAny(maxLen int) qrule {
	return qrule{check: func(v string) error {
		if len(v) > maxLen {
			return fmt.Errorf("longer than %d bytes", maxLen)
		}
		return nil
	}}
}

var reTail = regexp.MustCompile(`^(all|[0-9]{1,9})$`)
var reTimestamp = regexp.MustCompile(`^[0-9]{1,12}(\.[0-9]{1,9})?$`)

// route is one entry of the table: method, path pattern (":x" captures one
// segment), op and the allowed query keys.
type route struct {
	method  string
	pattern string
	op      op
	query   map[string]qrule
}

var routes = []route{
	{http.MethodGet, "/_ping", opPing, nil},
	{http.MethodHead, "/_ping", opPing, nil},
	{http.MethodGet, "/version", opVersion, nil},
	{http.MethodGet, "/info", opInfo, nil},
	{http.MethodGet, "/system/df", opDiskUsage, map[string]qrule{
		"type": {multi: true, check: qEnum("image", "container", "volume", "build-cache").check}, "verbose": qBool(),
	}},

	{http.MethodGet, "/containers/json", opContainerList, map[string]qrule{
		"all": qBool(), "filters": qAny(8 << 10), "limit": qInt(-1, 10000), "size": qBool(),
	}},
	{http.MethodPost, "/containers/create", opContainerCreate, map[string]qrule{
		"name": qRe(reObject), "platform": qRe(regexp.MustCompile(`^[a-z0-9]+/[a-z0-9]+(/[a-z0-9]+)?$`)),
	}},
	{http.MethodGet, "/containers/:id/json", opContainerInspect, map[string]qrule{"size": qBool()}},
	{http.MethodPost, "/containers/:id/start", opContainerStart, nil},
	// D42: stop timeouts are bounded like WorkloadSpec.stop (≤ 5 min).
	{http.MethodPost, "/containers/:id/stop", opContainerStop, map[string]qrule{"t": qInt(-1, 300)}},
	{http.MethodPost, "/containers/:id/pause", opContainerPause, nil},
	{http.MethodPost, "/containers/:id/unpause", opContainerUnpause, nil},
	{http.MethodPost, "/containers/:id/wait", opContainerWait, map[string]qrule{"condition": qEnum("not-running", "next-exit", "removed")}},
	{http.MethodDelete, "/containers/:id", opContainerRemove, map[string]qrule{"force": qBool(), "v": qFalse(), "link": qFalse()}},
	{http.MethodGet, "/containers/:id/logs", opContainerLogs, map[string]qrule{
		"stdout": qBool(), "stderr": qBool(), "timestamps": qBool(), "follow": qBool(), "details": qBool(),
		"tail": qRe(reTail), "since": qRe(reTimestamp), "until": qRe(reTimestamp),
	}},
	{http.MethodGet, "/containers/:id/stats", opContainerStats, map[string]qrule{"stream": qBool(), "one-shot": qBool()}},
	{http.MethodHead, "/containers/:id/archive", opArchiveHead, map[string]qrule{"path": qRe(reCtrPath)}},
	{http.MethodGet, "/containers/:id/archive", opArchiveGet, map[string]qrule{"path": qRe(reCtrPath)}},
	{http.MethodPut, "/containers/:id/archive", opArchivePut, map[string]qrule{
		"path": qRe(reCtrPath), "noOverwriteDirNonDir": qBool(), "copyUIDGID": qBool(),
	}},
	{http.MethodPost, "/containers/:id/exec", opExecCreate, nil},
	{http.MethodPost, "/exec/:exec/start", opExecStart, nil},
	{http.MethodPost, "/exec/:exec/resize", opExecResize, map[string]qrule{"h": qInt(0, 10000), "w": qInt(0, 10000)}},
	{http.MethodGet, "/exec/:exec/json", opExecInspect, nil},

	{http.MethodGet, "/networks", opNetworkList, map[string]qrule{"filters": qAny(8 << 10)}},
	{http.MethodGet, "/networks/:id", opNetworkInspect, map[string]qrule{"verbose": qFalse(), "scope": qEnum("local")}},
	{http.MethodPost, "/networks/create", opNetworkCreate, nil},
	{http.MethodDelete, "/networks/:id", opNetworkRemove, nil},
	{http.MethodPost, "/networks/:id/connect", opNetworkConnect, nil},
	{http.MethodPost, "/networks/:id/disconnect", opNetworkDisconnect, nil},

	{http.MethodGet, "/volumes", opVolumeList, map[string]qrule{"filters": qAny(8 << 10)}},
	{http.MethodGet, "/volumes/:id", opVolumeInspect, nil},
	{http.MethodPost, "/volumes/create", opVolumeCreate, nil},
	{http.MethodDelete, "/volumes/:id", opVolumeRemove, map[string]qrule{"force": qFalse()}},

	{http.MethodPost, "/images/create", opImageCreate, map[string]qrule{
		"fromImage": qRe(reImage), "tag": qAny(160), "platform": qAny(64),
		"fromSrc": qEnum("-"), "repo": qRe(reImage), "message": qAny(256),
		"changes": {multi: true, check: qAny(1024).check},
	}},
}

// request is a parsed, allow-listed request.
type request struct {
	op      op
	version string // "v1.NN" or "" (no prefix)
	// id is the object path parameter (container, network, volume, exec id
	// or image reference).
	id    string
	query url.Values
}

// apiPath renders an Engine API path with the request's version prefix.
func (q *request) apiPath(p string) string {
	if q == nil || q.version == "" {
		return p
	}
	return "/" + q.version + p
}

// parseRequest matches the request against the route table.
func parseRequest(r *http.Request) (*request, error) {
	raw := r.URL.EscapedPath()
	if raw != r.URL.Path || strings.Contains(raw, "%") {
		return nil, errors.New("escaped characters in the path are not allowed")
	}
	if strings.Contains(raw, "//") || strings.Contains(raw, "/./") || strings.Contains(raw, "/../") || strings.HasSuffix(raw, "/..") || strings.HasSuffix(raw, "/.") {
		return nil, errors.New("path is not clean")
	}
	q := &request{}
	p := raw
	if m := reVersion.FindStringSubmatch(raw); m != nil {
		q.version = "v1." + m[1]
		p = m[2]
	} else if strings.HasPrefix(raw, "/v") {
		return nil, fmt.Errorf("API version prefix of %s is not supported", truncate(raw, 64))
	}
	values, err := url.ParseQuery(r.URL.RawQuery)
	if err != nil {
		return nil, errors.New("malformed query")
	}

	rt, id, ok := match(r.Method, p)
	if !ok {
		return nil, fmt.Errorf("%s %s is not an allowed Engine API call", r.Method, truncate(p, 128))
	}
	q.op, q.id = rt.op, id
	for k, vs := range values {
		rule, ok := rt.query[k]
		if !ok {
			return nil, fmt.Errorf("query parameter %q is not allowed for %s %s", truncate(k, 32), r.Method, rt.pattern)
		}
		if len(vs) > 1 && !rule.multi {
			return nil, fmt.Errorf("query parameter %q repeated", k)
		}
		if len(vs) > 16 {
			return nil, fmt.Errorf("query parameter %q repeated too often", k)
		}
		for _, v := range vs {
			if err := rule.check(v); err != nil {
				return nil, fmt.Errorf("query parameter %s: %w", k, err)
			}
		}
	}
	q.query = values
	return q, nil
}

// match finds the route for method and path (without version prefix).
func match(method, p string) (route, string, bool) {
	// Image routes carry references with slashes: /images/<ref>/json and
	// DELETE /images/<ref>.
	if strings.HasPrefix(p, "/images/") && p != "/images/create" {
		ref := strings.TrimPrefix(p, "/images/")
		switch {
		case method == http.MethodGet && strings.HasSuffix(ref, "/json"):
			ref = strings.TrimSuffix(ref, "/json")
			if validImageRef(ref) {
				return route{method: method, pattern: "/images/:ref/json", op: opImageInspect}, ref, true
			}
		case method == http.MethodDelete:
			if validImageRef(ref) {
				return route{method: method, pattern: "/images/:ref", op: opImageRemove, query: map[string]qrule{"force": qFalse(), "noprune": qBool()}}, ref, true
			}
		}
		return route{}, "", false
	}
	segs := strings.Split(strings.TrimPrefix(p, "/"), "/")
	for _, rt := range routes {
		if rt.method != method {
			continue
		}
		ps := strings.Split(strings.TrimPrefix(rt.pattern, "/"), "/")
		if len(ps) != len(segs) {
			continue
		}
		id, ok := "", true
		for i, s := range ps {
			switch s {
			case ":id":
				if !reObject.MatchString(segs[i]) {
					ok = false
				}
				id = segs[i]
			case ":exec":
				if !reExecID.MatchString(segs[i]) {
					ok = false
				}
				id = segs[i]
			default:
				if s != segs[i] {
					ok = false
				}
			}
			if !ok {
				break
			}
		}
		if ok {
			return rt, id, true
		}
	}
	return route{}, "", false
}

func validImageRef(ref string) bool {
	return reImage.MatchString(ref) && !strings.Contains(ref, "//") && !strings.Contains(ref, "..") && !strings.HasSuffix(ref, "/")
}
