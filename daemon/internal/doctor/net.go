package doctor

import (
	"bufio"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/names"
)

// checkRegistry: Zot answers its API (401 without a token is an answer).
func (r *run) checkRegistry(ctx context.Context) {
	if c, ok := r.service("zot"); !ok || !c.Running {
		return
	}
	r.within(ctx, 0, func(ctx context.Context) {
		code, err := r.get(ctx, "http://"+r.o.RegistryAddr+"/v2/", "")
		res := Result{ID: "registry.api", Group: groupRegistry, Title: "Image registry", Status: StatusOK, Summary: "Answering."}
		if err != nil || (code != http.StatusOK && code != http.StatusUnauthorized) {
			res.Status = StatusFail
			res.Summary = "The image registry doesn't answer, so servers can't download images."
			if slices.Contains(restartable, "zot") {
				res.Fix = &FixRef{ID: FixRestart, Target: "zot", Does: "Restarts the image registry (pushes and pulls pause for a few seconds)."}
			}
		}
		r.add(res)
	})
}

// checkDashboard: the web container answers /up, and its last start did
// not stop at a failed migration.
func (r *run) checkDashboard(ctx context.Context) {
	c, ok := r.service("dashboard")
	if !ok {
		return
	}
	logs := ""
	r.within(ctx, 0, func(ctx context.Context) { logs, _ = r.o.Engine.Logs(ctx, c, 300) })
	if m := migrationFailure(logs); m != "" && !c.Healthy() {
		r.add(Result{ID: "dashboard.migrations", Group: groupDashboard, Title: "Database migrations", Status: StatusFail,
			Summary: "The dashboard can't start: its database migration failed (" + m + ").",
			Detail:  []string{"Its log: ./launchroom logs dashboard", "Run the installer of the version you had before to go back, or restore the last backup with ./launchroom restore."}})
	}
	if !c.Running {
		return
	}
	r.within(ctx, 0, func(ctx context.Context) {
		res := Result{ID: "dashboard.up", Group: groupDashboard, Title: "Dashboard", Status: StatusOK, Summary: "Answering on " + r.o.DashboardAddr + "."}
		code, err := r.get(ctx, "http://"+r.o.DashboardAddr+"/up", "")
		if err != nil || code != http.StatusOK {
			res.Status, res.Summary = StatusFail, "The dashboard doesn't answer on "+r.o.DashboardAddr+"."
			res.Fix = &FixRef{ID: FixRestart, Target: "dashboard", Does: "Restarts the dashboard."}
			r.add(res)
			return
		}
		// /up/ready (database and cache from the dashboard's view), when
		// this version has it.
		code, err = r.get(ctx, "http://"+r.o.DashboardAddr+"/up/ready", "")
		if err == nil && code != http.StatusOK && code != http.StatusNotFound {
			res.Status, res.Summary = StatusFail, "The dashboard answers, but can't reach its database or cache."
		}
		r.add(res)
	})
}

// artisanCheck is one entry of `php artisan launchroom:doctor --json`.
type artisanCheck struct {
	ID      string   `json:"id"`
	Title   string   `json:"title"`
	Status  Status   `json:"status"`
	Summary string   `json:"summary"`
	Detail  []string `json:"detail"`
	Fix     string   `json:"fix"`
}

// artisanFixes are the fixes the dashboard's own checks may name.
var artisanFixes = map[string]FixRef{
	string(FixClearScheduleLocks): {ID: FixClearScheduleLocks, Does: "Clears the schedule's locks, so commands a stopped scheduler left locked run again."},
	string(FixRestartWorkers):     {ID: FixRestartWorkers, Does: "Lets the background job workers finish their current job and start fresh."},
	string(FixCancelUpdate):       {ID: FixCancelUpdate, Does: "Cancels the update that made no progress. Nothing has changed yet; try it again from Settings → Updates."},
}

// checkArtisan runs the dashboard's own checks (queues, schedule, failed
// jobs, backups, migrations) when this version has them.
func (r *run) checkArtisan(ctx context.Context) {
	c, ok := r.service("dashboard")
	if !ok || !c.Running {
		return
	}
	r.within(ctx, 40*time.Second, func(ctx context.Context) {
		code, out, err := r.o.Engine.Exec(ctx, c, []string{"php", "artisan", "list", "--raw"})
		if err != nil || code != 0 {
			r.add(Result{ID: "dashboard.checks", Group: groupDashboard, Title: "Dashboard checks", Status: StatusSkip, Summary: "The dashboard's own checks couldn't run."})
			return
		}
		if !hasArtisanCommand(out, "launchroom:doctor") {
			r.add(Result{ID: "dashboard.checks", Group: groupDashboard, Title: "Dashboard checks", Status: StatusSkip, Summary: "This version of the dashboard has no checks of its own yet."})
			return
		}
		code, out, err = r.o.Engine.Exec(ctx, c, []string{"php", "artisan", "launchroom:doctor", "--json"})
		var doc struct {
			Checks []artisanCheck `json:"checks"`
		}
		if err != nil || (code != 0 && code != 1) || json.Unmarshal([]byte(jsonPart(out)), &doc) != nil {
			r.add(Result{ID: "dashboard.checks", Group: groupDashboard, Title: "Dashboard checks", Status: StatusFail, Summary: "The dashboard's own checks failed to run.",
				Detail: []string{clip(redactLine(lastLine(out)), 200)}})
			return
		}
		for _, a := range doc.Checks {
			if !reCheckID.MatchString(a.ID) || !slices.Contains([]Status{StatusOK, StatusWarn, StatusFail, StatusSkip}, a.Status) {
				continue
			}
			res := Result{ID: "dashboard." + a.ID, Group: groupDashboard, Title: clip(a.Title, 60), Status: a.Status, Summary: clip(a.Summary, 300)}
			for _, d := range a.Detail {
				res.Detail = append(res.Detail, clip(d, 300))
			}
			if f, ok := artisanFixes[a.Fix]; ok {
				res.Fix = &f
			}
			r.add(res)
		}
	})
}

func hasArtisanCommand(list, name string) bool {
	for l := range strings.SplitSeq(list, "\n") {
		if f := strings.Fields(l); len(f) > 0 && f[0] == name {
			return true
		}
	}
	return false
}

// jsonPart is the output from its first "{" (artisan may print warnings first).
func jsonPart(s string) string {
	if i := strings.IndexByte(s, '{'); i >= 0 {
		return s[i:]
	}
	return s
}

// checkAddress: the edge Caddy holds 80/443, the dashboard's host name
// resolves, and its certificate is valid.
func (r *run) checkAddress(ctx context.Context) {
	if _, ok := r.byName(names.SystemPrefix + "caddy"); !ok {
		r.add(Result{ID: "address.caddy", Group: groupAddress, Title: "Web server", Status: StatusFail,
			Summary: "launchroom's web server isn't running, so the dashboard and apps aren't reachable from outside.",
			Detail:  []string{"launchroomd starts it; check launchroomd above."}})
		return
	}
	r.checkPorts()
	host := r.dashboardHost(ctx)
	if host == "" {
		return
	}
	r.within(ctx, 0, func(ctx context.Context) { r.checkDNS(ctx, host) })
	r.within(ctx, 0, func(ctx context.Context) { r.checkTLS(ctx, host) })
}

func (r *run) checkPorts() {
	listening := listeningPorts(r.o.ProcDir)
	var missing []string
	for _, p := range []int{80, 443} {
		if !listening[p] {
			missing = append(missing, itoa(p))
		}
	}
	res := Result{ID: "address.ports", Group: groupAddress, Title: "Ports 80 and 443", Status: StatusOK, Summary: "launchroom's web server listens on 80 and 443."}
	if c, ok := r.byName(names.SystemPrefix + "caddy"); ok && !c.Running {
		res.Status, res.Summary = StatusFail, "launchroom's web server isn't running."
	} else if len(missing) > 0 {
		res.Status = StatusFail
		res.Summary = "Nothing listens on port " + strings.Join(missing, " and ") + "."
		res.Detail = []string{"Another program may hold the port, or the web server failed to start. The server page in the dashboard shows which."}
	}
	r.add(res)
}

// listeningPorts reads the TCP listeners of the doctor's network
// namespace (the host's: it runs with the host network).
func listeningPorts(proc string) map[int]bool {
	out := map[int]bool{}
	for _, f := range []string{"net/tcp", "net/tcp6"} {
		fh, err := os.Open(filepath.Join(proc, f)) //nolint:gosec // /proc/net/tcp{,6}
		if err != nil {
			continue
		}
		sc := bufio.NewScanner(fh)
		for sc.Scan() {
			fields := strings.Fields(sc.Text())
			if len(fields) < 4 || fields[3] != "0A" { // LISTEN
				continue
			}
			_, port, ok := strings.Cut(fields[1], ":")
			if !ok {
				continue
			}
			var p int
			if _, err := fmt.Sscanf(port, "%X", &p); err == nil {
				out[p] = true
			}
		}
		_ = fh.Close()
	}
	return out
}

// dashboardHost is the dashboard's canonical host name (Settings →
// Dashboard domain, else APP_URL).
func (r *run) dashboardHost(ctx context.Context) string {
	raw := r.env["APP_URL"]
	if c, ok := r.service("dashboard"); ok && c.Running {
		r.within(ctx, 20*time.Second, func(ctx context.Context) {
			code, out, err := r.o.Engine.Exec(ctx, c, []string{"php", "artisan", "launchroom:dashboard-url"})
			if l := lastLine(out); err == nil && code == 0 && (strings.HasPrefix(l, "https://") || strings.HasPrefix(l, "http://")) {
				raw = l
			}
		})
	}
	u, err := url.Parse(raw)
	if err != nil {
		return ""
	}
	return u.Hostname()
}

func isLocalName(host string) bool {
	return host == "localhost" || strings.HasSuffix(host, ".localhost") || net.ParseIP(host) != nil
}

func (r *run) checkDNS(ctx context.Context, host string) {
	if isLocalName(host) {
		return
	}
	res := Result{ID: "address.dns", Group: groupAddress, Title: "DNS", Status: StatusOK}
	addrs, err := r.o.Resolver.LookupHost(ctx, host)
	if err != nil || len(addrs) == 0 {
		res.Status, res.Summary = StatusFail, host+" doesn't resolve to an address."
		res.Detail = []string{"Point an A (or AAAA) record for " + host + " at this server."}
		r.add(res)
		return
	}
	res.Summary = host + " points to " + strings.Join(addrs, ", ") + "."
	if want := r.env["LR_PUBLIC_IPS"]; want != "" {
		match := false
		for _, a := range addrs {
			if slices.Contains(strings.Split(want, ","), a) {
				match = true
			}
		}
		if !match {
			res.Status = StatusWarn
			res.Detail = []string{"This server's public address is " + want + "."}
		}
	}
	r.add(res)
}

// checkTLS shakes hands with the edge Caddy on 127.0.0.1:443 for the
// dashboard's host name and reads its certificate.
func (r *run) checkTLS(ctx context.Context, host string) {
	res := Result{ID: "address.certificate", Group: groupAddress, Title: "Certificate", Status: StatusOK}
	d := tls.Dialer{Config: &tls.Config{ServerName: host, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}} //nolint:gosec // verified below against the system roots, with the result reported instead of refused
	conn, err := d.DialContext(ctx, "tcp", r.o.TLSAddr)
	if err != nil {
		res.Status, res.Summary = StatusFail, "No HTTPS answer for "+host+" on this server."
		res.Detail = []string{clip(err.Error(), 200)}
		r.add(res)
		return
	}
	defer func() { _ = conn.Close() }()
	tc, ok := conn.(*tls.Conn)
	if !ok || len(tc.ConnectionState().PeerCertificates) == 0 {
		res.Status, res.Summary = StatusFail, "No certificate for "+host+"."
		r.add(res)
		return
	}
	certs := tc.ConnectionState().PeerCertificates
	leaf := certs[0]
	left := leaf.NotAfter.Sub(r.o.Now())
	lifetime := leaf.NotAfter.Sub(leaf.NotBefore)
	issuer := leaf.Issuer.CommonName
	if len(leaf.Issuer.Organization) > 0 {
		issuer = leaf.Issuer.Organization[0]
	}
	res.Summary = fmt.Sprintf("Valid for %s more, from %s.", humanLeft(left), issuer)
	pool := x509.NewCertPool()
	for _, c := range certs[1:] {
		pool.AddCert(c)
	}
	_, verr := leaf.Verify(x509.VerifyOptions{DNSName: host, Intermediates: pool, CurrentTime: r.o.Now()})
	// Caddy renews a certificate when a third of its lifetime is left, so
	// judge by that share rather than by days: short-lived certificates
	// (Caddy's local authority, 6-day ACME certificates) are fine at hours.
	switch {
	case left <= 0:
		res.Status, res.Summary = StatusFail, "The certificate for "+host+" expired "+humanLeft(-left)+" ago."
	case lifetime > 0 && left < lifetime/10:
		res.Status = StatusFail
	case lifetime > 0 && left < lifetime/4:
		res.Status = StatusWarn
		res.Detail = append(res.Detail, "It should have been renewed by now. The domain's page in the dashboard shows why it wasn't.")
	}
	if verr != nil && !isLocalName(host) {
		res.Status = StatusFail
		res.Summary = "The certificate for " + host + " isn't trusted by browsers (" + issuer + ")."
		res.Detail = append(res.Detail, clip(verr.Error(), 200))
	}
	r.add(res)
}

// checkControlPlaneConnection (server mode): the dashboard answers, and
// this server's clock agrees with it.
func (r *run) checkControlPlaneConnection(ctx context.Context) {
	if r.o.ControlPlaneURL == "" {
		return
	}
	r.within(ctx, 0, func(ctx context.Context) {
		res := Result{ID: "daemon.dashboard", Group: groupDaemon, Title: "Dashboard connection", Status: StatusOK}
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, strings.TrimRight(r.o.ControlPlaneURL, "/")+"/up", nil)
		if err != nil {
			return
		}
		resp, err := r.o.HTTP.Do(req)
		if err != nil {
			res.Status, res.Summary = StatusFail, "Can't reach the dashboard at "+r.o.ControlPlaneURL+"."
			res.Detail = []string{clip(err.Error(), 200), "Apps keep running; they get changes again once it is reachable."}
			r.add(res)
			return
		}
		_ = resp.Body.Close()
		res.Summary = "The dashboard at " + r.o.ControlPlaneURL + " answers."
		if resp.StatusCode != http.StatusOK {
			res.Status = StatusWarn
			res.Summary = fmt.Sprintf("The dashboard at %s answers with HTTP %d.", r.o.ControlPlaneURL, resp.StatusCode)
		}
		if t, err := http.ParseTime(resp.Header.Get("Date")); err == nil {
			skew := r.o.Now().Sub(t)
			if skew < 0 {
				skew = -skew
			}
			if skew > 2*time.Minute {
				res.Status = StatusFail
				res.Detail = append(res.Detail, fmt.Sprintf("This server's clock is %s off from the dashboard's, so the dashboard refuses its requests. Turn on time synchronisation (timedatectl set-ntp true).", skew.Round(time.Second)))
			} else if skew > 30*time.Second {
				res.Status = StatusWarn
				res.Detail = append(res.Detail, fmt.Sprintf("This server's clock is %s off from the dashboard's.", skew.Round(time.Second)))
			}
		}
		r.add(res)
	})
}

// get requests url and returns the status code.
func (r *run) get(ctx context.Context, u, host string) (int, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return 0, err
	}
	if host != "" {
		req.Host = host
	}
	resp, err := r.o.HTTP.Do(req)
	if err != nil {
		return 0, err
	}
	_ = resp.Body.Close()
	return resp.StatusCode, nil
}

// humanLeft is a duration in days, or in hours below two days.
func humanLeft(d time.Duration) string {
	if h := int(d.Hours()); h < 48 {
		if h == 1 {
			return "1 hour"
		}
		return itoa(h) + " hours"
	}
	return itoa(int(d.Hours()/24)) + " days"
}
