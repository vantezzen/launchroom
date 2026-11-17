package presets

import (
	"bufio"
	"io/fs"
	"regexp"
	"strings"
)

// RubyFacts are facts about a Ruby app (Gemfile, Rails config).
type RubyFacts struct {
	// Gems are the gem names in Gemfile.lock (falling back to the Gemfile).
	Gems map[string]bool
	// Rails is set when config/application.rb defines a Rails::Application.
	Rails bool
	// RailsVersion is the locked rails version ("8.0.1"), if known.
	RailsVersion string
	// HealthPath is the route to rails/health#show ("/up"), if any.
	HealthPath string
	// ProductionSQLite is set when config/database.yml's production
	// database uses the sqlite3 adapter.
	ProductionSQLite bool
	// LocalStorage is set when Active Storage keeps files on the local disk
	// in production.
	LocalStorage bool
	// Credentials is the encrypted credentials file production reads, if
	// any, and MasterKey is set when its key is committed (config/master.key).
	Credentials string
	MasterKey   bool
	// BinRails and BinJobs are set when bin/rails and bin/jobs (Solid Queue) exist.
	BinRails bool
	BinJobs  bool
}

var (
	reGemfileGem  = regexp.MustCompile(`(?m)^\s*gem\s+['"]([A-Za-z0-9_.-]+)['"]`)
	reLockSpec    = regexp.MustCompile(`^    ([A-Za-z0-9_.-]+) \(([^)]+)\)`)
	reRailsHealth = regexp.MustCompile(`(?m)^\s*get\s*\(?\s*['"]/?([A-Za-z0-9_./-]*)['"]\s*(?:=>|,\s*to:)\s*['"]rails/health#show['"]`)
	reYAMLTopKey  = regexp.MustCompile(`^[A-Za-z0-9_]+:`)
	reYAMLAnchor  = regexp.MustCompile(`^([A-Za-z0-9_]+):\s*&([A-Za-z0-9_]+)`)
	reAdapter     = regexp.MustCompile(`adapter:\s*['"]?([a-z0-9_]+)`)
	reAliasMerge  = regexp.MustCompile(`<<:\s*\*([A-Za-z0-9_]+)`)
	reStorageProd = regexp.MustCompile(`(?m)^\s*config\.active_storage\.service\s*=\s*:local\b`)
)

func gatherRuby(fsys fs.FS, f *Facts) {
	if !f.Files["Gemfile"] {
		return
	}
	r := RubyFacts{Gems: map[string]bool{}}
	if s, ok := readSmall(fsys, "Gemfile.lock"); ok {
		sc := bufio.NewScanner(strings.NewReader(s))
		inSpecs := false
		for sc.Scan() {
			line := sc.Text()
			if strings.TrimSpace(line) == "specs:" {
				inSpecs = true
				continue
			}
			if line != "" && !strings.HasPrefix(line, " ") {
				inSpecs = false
			}
			if m := reLockSpec.FindStringSubmatch(line); inSpecs && m != nil {
				r.Gems[m[1]] = true
				if m[1] == "rails" {
					r.RailsVersion = m[2]
				}
			}
		}
	}
	if s, ok := readSmall(fsys, "Gemfile"); ok {
		for _, m := range reGemfileGem.FindAllStringSubmatch(s, -1) {
			r.Gems[m[1]] = true
		}
	}
	if s, ok := readSmall(fsys, "config/application.rb"); ok && strings.Contains(s, "Rails::Application") {
		r.Rails = true
	}
	if !r.Rails {
		f.Ruby = r
		return
	}
	if s, ok := readSmall(fsys, "config/routes.rb"); ok {
		if m := reRailsHealth.FindStringSubmatch(s); m != nil {
			r.HealthPath = "/" + strings.TrimPrefix(m[1], "/")
		}
	}
	if s, ok := readSmall(fsys, "config/database.yml"); ok {
		r.ProductionSQLite = productionAdapter(s) == "sqlite3"
	}
	if s, ok := readSmall(fsys, "config/environments/production.rb"); ok {
		r.LocalStorage = reStorageProd.MatchString(s)
	}
	for _, c := range []string{"config/credentials/production.yml.enc", "config/credentials.yml.enc"} {
		if isFile(fsys, c) {
			r.Credentials = c
			r.MasterKey = isFile(fsys, strings.TrimSuffix(c, ".yml.enc")+".key") || (c == "config/credentials.yml.enc" && isFile(fsys, "config/master.key"))
			break
		}
	}
	r.BinRails, r.BinJobs = isFile(fsys, "bin/rails"), isFile(fsys, "bin/jobs")
	f.Ruby = r
}

// productionAdapter returns the adapter of config/database.yml's production
// database: its own adapter, the first one of a multi-database setup, or the
// adapter of the anchor it merges (<<: *default). ERB is not evaluated.
func productionAdapter(yml string) string {
	sections := map[string]string{}
	anchors := map[string]string{}
	var key string
	var body strings.Builder
	flush := func() {
		if key != "" {
			sections[key] = body.String()
		}
		body.Reset()
	}
	for _, line := range strings.Split(yml, "\n") {
		if reYAMLTopKey.MatchString(line) {
			flush()
			key = line[:strings.Index(line, ":")]
			if m := reYAMLAnchor.FindStringSubmatch(line); m != nil {
				anchors[m[2]] = m[1]
			}
			continue
		}
		if t := strings.TrimSpace(line); t != "" && !strings.HasPrefix(t, "#") {
			body.WriteString(line + "\n")
		}
	}
	flush()
	prod, ok := sections["production"]
	if !ok {
		return ""
	}
	if m := reAdapter.FindStringSubmatch(prod); m != nil {
		return m[1]
	}
	if m := reAliasMerge.FindStringSubmatch(prod); m != nil {
		if m := reAdapter.FindStringSubmatch(sections[anchors[m[1]]]); m != nil {
			return m[1]
		}
	}
	return ""
}
