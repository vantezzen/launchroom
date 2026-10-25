package state

import (
	stdjson "encoding/json"
	"net/netip"
	"strings"
	"testing"
)

// Contract tests of the security center documents (docs/22, docs/03 §13):
// each case changes one fixture so that exactly one rule fails.

func TestSecurityFixturesRejectBrokenRules(t *testing.T) {
	type obj = map[string]any
	type arr = []any
	lease := decodeAs[TaskLease]
	completion := decodeAs[TaskCompletion]
	report := decodeAs[Report]
	node := decodeAs[NodeState]
	spec := func(d obj, i int) obj { return d["tasks"].(arr)[i].(obj)["spec"].(obj) }
	inv := func(d obj) obj { return d["inventory"].(obj) }
	comp := func(d obj, i int) obj { return inv(d)["components"].(arr)[i].(obj) }
	insp := func(d obj) obj { return d["containerInspection"].(obj) }
	change := func(d obj, i int) obj { return insp(d)["changes"].(obj)["entries"].(arr)[i].(obj) }
	route := func(d obj, i int) obj { return d["routes"].(arr)[i].(obj)["waf"].(obj) }
	sample := func(d obj, i int) obj { return d["metrics"].(obj)["waf"].(arr)[i].(obj) }
	event := func(d obj, i, j int) obj { return sample(d, i)["events"].(arr)[j].(obj) }
	type tc struct {
		fixture string
		decode  func([]byte) error
		mutate  func(d obj)
	}
	cases := map[string]tc{
		"inventory: apps image without service":    {"task-lease.inventory.json", lease, func(d obj) { delete(spec(d, 0), "service") }},
		"inventory: apps image of another service": {"task-lease.inventory.json", lease, func(d obj) { spec(d, 0)["service"] = "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N3" }},
		"inventory: mirror image with a service":   {"task-lease.inventory.json", lease, func(d obj) { spec(d, 1)["service"] = "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N3" }},
		"inventory: image outside apps and mirror": {"task-lease.inventory.json", lease, func(d obj) {
			spec(d, 1)["image"] = strings.Replace(spec(d, 1)["image"].(string), "/mirror/", "/cache/", 1)
		}},
		"inventory: ssh key with an https remote": {"task-lease.inventory.json", lease, func(d obj) {
			src := spec(d, 0)["source"].(obj)
			src["sshKey"] = src["cloneToken"]
			delete(src, "cloneToken")
		}},
		"inventory: bundled os package":             {"task-completion.inventory.json", completion, func(d obj) { comp(d, 6)["presence"] = "bundled" }},
		"inventory: dev runtime":                    {"task-completion.inventory.json", completion, func(d obj) { comp(d, 0)["scope"] = "dev" }},
		"inventory: library from a deb":             {"task-completion.inventory.json", completion, func(d obj) { comp(d, 6)["kind"] = "library" }},
		"inventory: version grammar":                {"task-completion.inventory.json", completion, func(d obj) { comp(d, 2)["version"] = "4.17.20 beta" }},
		"inventory: duplicate component":            {"task-completion.inventory.json", completion, func(d obj) { comp(d, 4)["name"] = "lodash"; comp(d, 4)["version"] = "4.17.20" }},
		"inventory: truncated below the limit":      {"task-completion.inventory.json", completion, func(d obj) { inv(d)["truncated"] = true }},
		"inspection: name instead of container id":  {"task-completion.container-inspection.json", completion, func(d obj) { insp(d)["containerId"] = "web-1" }},
		"inspection: control character in a path":   {"task-completion.container-inspection.json", completion, func(d obj) { change(d, 0)["path"] = "/tmp/.x/k\x1bworker" }},
		"inspection: stat fields on a deleted path": {"task-completion.container-inspection.json", completion, func(d obj) { change(d, 3)["type"] = "file" }},
		"inspection: counts exceed total":           {"task-completion.container-inspection.json", completion, func(d obj) { insp(d)["changes"].(obj)["total"] = 20 }},
		"inspection: unknown process flag":          {"task-completion.container-inspection.json", completion, func(d obj) { insp(d)["processes"].(obj)["entries"].(arr)[0].(obj)["flags"] = arr{"root"} }},
		"waf: exclusion of an evaluation rule":      {"node-state.waf.json", node, func(d obj) { route(d, 0)["exclusions"].(arr)[1].(obj)["rule"] = 949110 }},
		"waf: target grammar":                       {"node-state.waf.json", node, func(d obj) { route(d, 0)["exclusions"].(arr)[0].(obj)["target"] = `ARGS:q" phase:1` }},
		"waf: paranoia 3":                           {"node-state.waf.json", node, func(d obj) { route(d, 1)["paranoia"] = 3 }},
		"waf: inspectBodyKiB 64":                    {"node-state.waf.json", node, func(d obj) { route(d, 1)["inspectBodyKiB"] = 64 }},
		"waf: unknown mode":                         {"node-state.waf.json", node, func(d obj) { route(d, 1)["mode"] = "off" }},
		"waf: duplicate exclusion":                  {"node-state.waf.json", node, func(d obj) { x := route(d, 0)["exclusions"].(arr); route(d, 0)["exclusions"] = append(x, x[0]) }},
		"waf: path counts don't add up":             {"report.waf.json", report, func(d obj) { sample(d, 0)["otherPaths"] = obj{"blocked": 4} }},
		"waf: category above the sample's count":    {"report.waf.json", report, func(d obj) { sample(d, 0)["categories"].(arr)[0].(obj)["blocked"] = 15 }},
		"waf: window not aligned":                   {"report.waf.json", report, func(d obj) { sample(d, 0)["at"] = "2026-10-17T12:01:00Z" }},
		"waf: event outside its window":             {"report.waf.json", report, func(d obj) { event(d, 0, 0)["at"] = "2026-10-17T12:13:12Z" }},
		"waf: event without rules":                  {"report.waf.json", report, func(d obj) { event(d, 1, 0)["rules"] = arr{} }},
		"waf: event with a query string":            {"report.waf.json", report, func(d obj) { event(d, 1, 0)["path"] = "/search?q=x" }},
		"waf: full client address":                  {"report.waf.json", report, func(d obj) { event(d, 0, 0)["clientNetwork"] = "203.0.113.7/32" }},
		"waf: client network with host bits":        {"report.waf.json", report, func(d obj) { event(d, 0, 0)["clientNetwork"] = "203.0.113.7/24" }},
		"waf: ipv6 network wider than /48":          {"report.waf.json", report, func(d obj) { event(d, 0, 1)["clientNetwork"] = "2001:db8::/32" }},
		"waf: old client field":                     {"report.waf.json", report, func(d obj) { e := event(d, 0, 0); e["client"] = "203.0.113.7"; delete(e, "clientNetwork") }},
		"waf: state on without routes":              {"report.waf.json", report, func(d obj) { d["ingress"].(obj)["waf"].(obj)["routes"] = 0 }},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			base := []byte(readFixture(t, c.fixture))
			if err := c.decode(base); err != nil {
				t.Fatalf("fixture itself is invalid: %v", err)
			}
			var d obj
			if err := stdjson.Unmarshal(base, &d); err != nil {
				t.Fatal(err)
			}
			c.mutate(d)
			doc, err := stdjson.Marshal(d)
			if err != nil {
				t.Fatal(err)
			}
			if err := c.decode(doc); err == nil {
				t.Fatal("accepted")
			}
		})
	}
}

func TestWafCategoryOf(t *testing.T) {
	cases := map[WafRuleID]WafCategory{
		911100: WafCategoryMethod, 913100: WafCategoryScanner, 920350: WafCategoryProtocol, 922110: WafCategoryProtocol,
		930120: WafCategoryLFI, 931100: WafCategoryRFI, 932100: WafCategoryRCE, 933100: WafCategoryPHP,
		934100: WafCategoryGeneric, 941100: WafCategoryXSS, 942100: WafCategorySQLi, 943100: WafCategorySessionFixation,
		944100: WafCategoryJava, 949110: WafCategoryOther, 901001: WafCategoryOther,
	}
	for rule, want := range cases {
		if got := WafCategoryOf(rule); got != want {
			t.Errorf("WafCategoryOf(%d) = %s, want %s", rule, got, want)
		}
	}
}

func TestClientNetworkOf(t *testing.T) {
	cases := map[string]ClientNetwork{
		"203.0.113.7":        "203.0.113.0/24",
		"::ffff:203.0.113.7": "203.0.113.0/24",
		"2001:db8:17:4::1":   "2001:db8:17::/48",
		"2001:db8::17":       "2001:db8::/48",
	}
	for in, want := range cases {
		got := ClientNetworkOf(netip.MustParseAddr(in))
		if got != want {
			t.Errorf("ClientNetworkOf(%s) = %s, want %s", in, got, want)
		}
		if err := got.Validate(); err != nil {
			t.Errorf("%s: %v", got, err)
		}
	}
}
