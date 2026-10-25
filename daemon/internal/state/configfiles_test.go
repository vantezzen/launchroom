package state

import (
	"strings"
	"testing"
)

func TestConfigFiles(t *testing.T) {
	mounts := []Mount{{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Target: "/var/lib/postgresql/data"}}
	ok := func(p, c string) ConfigFile { return ConfigFile{Path: ConfigFilePath(p), Content: c} }
	secretish := "SUPERSECRETCONTENT"
	cases := map[string]struct {
		files []ConfigFile
		valid bool
	}{
		"none":             {nil, true},
		"two files":        {[]ConfigFile{ok("/docker-entrypoint-initdb.d/init-scripts/99-roles.sql", "ALTER USER x;\n"), ok("/etc/app/conf.yml", "")}, true},
		"relative":         {[]ConfigFile{ok("etc/app.conf", "x")}, false},
		"dot segment":      {[]ConfigFile{ok("/etc/../app.conf", "x")}, false},
		"trailing slash":   {[]ConfigFile{ok("/etc/app/", "x")}, false},
		"double slash":     {[]ConfigFile{ok("/etc//app.conf", "x")}, false},
		"space":            {[]ConfigFile{ok("/etc/app conf", "x")}, false},
		"proc":             {[]ConfigFile{ok("/proc/self/x", "x")}, false},
		"sys":              {[]ConfigFile{ok("/sys/x", "x")}, false},
		"dev":              {[]ConfigFile{ok("/dev/x", "x")}, false},
		"hosts":            {[]ConfigFile{ok("/etc/hosts", "x")}, false},
		"resolv.conf":      {[]ConfigFile{ok("/etc/resolv.conf", "x")}, false},
		"hostname":         {[]ConfigFile{ok("/etc/hostname", "x")}, false},
		"too long path":    {[]ConfigFile{ok("/"+strings.Repeat("a", 255), "x")}, false},
		"duplicate":        {[]ConfigFile{ok("/a.conf", "x"), ok("/a.conf", "y")}, false},
		"parent of other":  {[]ConfigFile{ok("/etc/app", "x"), ok("/etc/app/conf", "y")}, false},
		"inside mount":     {[]ConfigFile{ok("/var/lib/postgresql/data/pg_hba.conf", "x")}, false},
		"equals mount":     {[]ConfigFile{ok("/var/lib/postgresql/data", "x")}, false},
		"sibling of mount": {[]ConfigFile{ok("/var/lib/postgresql/data2", "x")}, true},
		"nul":              {[]ConfigFile{ok("/a.conf", secretish+"\x00")}, false},
		"invalid utf8":     {[]ConfigFile{ok("/a.conf", secretish+"\xff")}, false},
		"too large":        {[]ConfigFile{ok("/a.conf", secretish+strings.Repeat("a", MaxConfigFileBytes))}, false},
		"max size":         {[]ConfigFile{ok("/a.conf", strings.Repeat("a", MaxConfigFileBytes))}, true},
	}
	var many, big []ConfigFile
	for i := range MaxConfigFiles + 1 {
		many = append(many, ok("/f"+strings.Repeat("x", i), "x"))
	}
	for i := range 5 {
		big = append(big, ok("/big"+strings.Repeat("x", i), strings.Repeat("a", MaxConfigFileBytes)))
	}
	cases["too many"] = struct {
		files []ConfigFile
		valid bool
	}{many, false}
	cases["too large in total"] = struct {
		files []ConfigFile
		valid bool
	}{big, false}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			err := CheckConfigFiles(c.files, mounts)
			if (err == nil) != c.valid {
				t.Fatalf("valid=%v, err=%v", c.valid, err)
			}
			if err != nil && strings.Contains(err.Error(), secretish) {
				t.Fatalf("error echoes content: %v", err)
			}
		})
	}
}

func TestWorkloadSpecValidatesFiles(t *testing.T) {
	ns := validNodeState(t)
	ns.Workloads[0].Files = []ConfigFile{{Path: "/etc/app.conf", Content: "a=1\n"}}
	if err := ns.Validate(); err != nil {
		t.Fatalf("valid files refused: %v", err)
	}
	ns.Workloads[0].Files = []ConfigFile{{Path: "/proc/x", Content: "a"}}
	if err := ns.Validate(); err == nil || !strings.Contains(err.Error(), "files") {
		t.Fatalf("bad file accepted: %v", err)
	}
}
