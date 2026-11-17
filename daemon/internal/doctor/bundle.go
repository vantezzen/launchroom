package doctor

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const bundleLogLines = 500

// WriteBundle writes a support bundle (a .tar.gz, mode 0600) next to the
// install's data and returns its path. It holds the report, the
// containers' state and inspect documents without their environment, the
// last log lines of the control plane's and launchroom's system containers,
// the update results and the image pins; every text passes the Redactor
// first. It never holds .env, key files, NodeState bodies or database rows.
func WriteBundle(ctx context.Context, o Options, rep Report) (string, error) {
	o.defaults()
	r := &run{o: o, env: map[string]string{}}
	r.load(ctx)
	red := NewRedactor(r.env)
	files := map[string][]byte{}
	add := func(name, text string) { files[name] = []byte(red.Redact(text)) }

	doc, _ := json.MarshalIndent(rep, "", "  ")
	add("doctor.json", string(doc))

	var list strings.Builder
	sort.Slice(r.containers, func(i, j int) bool { return r.containers[i].Name < r.containers[j].Name })
	for _, c := range r.containers {
		_, _ = fmt.Fprintf(&list, "%-34s %-14s %-10s health=%-9s exit=%-4d restarts=%-3d oom=%-5t %s\n",
			c.Name, c.Service, c.Status, c.Health, c.ExitCode, c.RestartCount, c.OOMKilled, c.Image)
		if !bundleContainer(c) {
			continue
		}
		cctx, cancel := context.WithTimeout(ctx, 15*time.Second)
		if b, err := o.Engine.InspectJSON(cctx, c); err == nil {
			add("inspect/"+c.Name+".json", string(b))
		}
		if logs, err := o.Engine.Logs(cctx, c, bundleLogLines); err == nil {
			add("logs/"+c.Name+".log", logs)
		}
		cancel()
	}
	add("containers.txt", list.String())

	var pins []string
	for k, v := range r.env {
		if strings.HasPrefix(k, "LR_") && strings.HasSuffix(k, "_IMAGE") {
			pins = append(pins, k+"="+v)
		}
	}
	sort.Strings(pins)
	add("versions.txt", fmt.Sprintf("launchroomd (doctor) %s\n%s\n", o.Version, strings.Join(pins, "\n")))

	if st, err := os.Stat(filepath.Join(o.DataDir, "state.json")); err == nil {
		add("daemon/state.txt", fmt.Sprintf("state.json: %d bytes, changed %s\n", st.Size(), st.ModTime().UTC().Format(time.RFC3339)))
	}
	for _, f := range []string{"enrollment.json", "update/running.json", "update/pending.json"} {
		if b, err := os.ReadFile(filepath.Join(o.DataDir, f)); err == nil && len(b) < 64<<10 { //nolint:gosec // fixed names in launchroomd's data folder
			add("daemon/"+f, string(b))
		}
	}
	addUpdateTasks(o.DataDir, add)

	dir := filepath.Join(o.DataDir, "doctor")
	if o.Mode == ModeControlPlane {
		dir = filepath.Join(o.Dir, "data", "doctor")
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	path := filepath.Join(dir, "launchroom-support-"+o.Now().UTC().Format("20060102-150405")+".tar.gz")
	b, err := tarGz(files, o.Now())
	if err != nil {
		return "", err
	}
	return path, os.WriteFile(path, b, 0o600)
}

// bundleContainer: the control plane's services and launchroom's system
// containers. App containers are left out: their logs are the user's data.
func bundleContainer(c Container) bool {
	return c.Project == cpProject || c.Role == "launchroomd" || c.Role == "dockerd-guard" || strings.HasPrefix(c.Name, names.SystemPrefix)
}

// addUpdateTasks adds each update's status and saved diagnostics (never the
// bundle, the plan or the backup of .env).
func addUpdateTasks(dataDir string, add func(name, text string)) {
	root := filepath.Join(dataDir, "update", "tasks")
	entries, err := os.ReadDir(root)
	if err != nil {
		return
	}
	for _, e := range entries {
		if !e.IsDir() || state.TaskID(e.Name()).Validate() != nil {
			continue
		}
		tdir := filepath.Join(root, e.Name())
		if b, err := os.ReadFile(filepath.Join(tdir, "status.json")); err == nil { //nolint:gosec // inside launchroomd's data folder
			add("update/"+e.Name()+"/status.json", string(b))
		}
		diag, _ := os.ReadDir(filepath.Join(tdir, "diagnostics"))
		for _, d := range diag {
			if !d.Type().IsRegular() {
				continue
			}
			if b, err := os.ReadFile(filepath.Join(tdir, "diagnostics", d.Name())); err == nil && len(b) < 1<<20 { //nolint:gosec // written by the supervisor
				add("update/"+e.Name()+"/diagnostics/"+d.Name(), string(b))
			}
		}
	}
}

func tarGz(files map[string][]byte, now time.Time) ([]byte, error) {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	keys := make([]string, 0, len(files))
	for n := range files {
		keys = append(keys, n)
	}
	sort.Strings(keys)
	for _, n := range keys {
		if err := tw.WriteHeader(&tar.Header{Name: "launchroom-support/" + n, Mode: 0o600, Size: int64(len(files[n])), ModTime: now, Typeflag: tar.TypeReg}); err != nil {
			return nil, err
		}
		if _, err := tw.Write(files[n]); err != nil {
			return nil, err
		}
	}
	if err := tw.Close(); err != nil {
		return nil, err
	}
	if err := gz.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
