package commands

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/output"
)

// Data import and export (docs/14 §12, D55): `db import`, `db export` and
// `volume export`. Files go through the dashboard in chunks; uploads resume
// where they stopped.

// dataPollEvery is how often import and export runs are polled.
var dataPollEvery = 2 * time.Second

// dataSleep waits between polls; tests replace it.
var dataSleep = func(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

func (a *App) dbCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "db",
		Short: "Import data into a database, or download it",
	}
	cmd.AddCommand(a.dbImportCmd(), a.dbExportCmd())
	return cmd
}

func (a *App) volumeCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "volume",
		Short: "Download a service volume's files",
	}
	cmd.AddCommand(a.volumeExportCmd())
	return cmd
}

// dataTarget resolves <service> (the positional argument wins over --service and the link).
func (a *App) dataTarget(ctx context.Context, service string) (*session, *target, error) {
	s, err := a.session()
	if err != nil {
		return nil, nil, err
	}
	if service != "" {
		a.flags.service = service
	}
	t, err := a.resolve(ctx, s, true)
	return s, t, err
}

func (a *App) dbImportCmd() *cobra.Command {
	var replace, keepRunning, yes bool
	cmd := &cobra.Command{
		Use:   "import <service> <file>",
		Short: "Import a dump file into a database",
		Long: "Uploads a dump (pg_dump -Fc or plain SQL, mysqldump SQL, mongodump --archive; .gz works too) and imports it into the database.\n\n" +
			"An upload that was interrupted continues where it stopped when you run the same command again.\n" +
			"A database that isn't empty is only replaced with --replace: launchroom takes a safety snapshot first,\n" +
			"then drops and recreates the database. The apps that use it are stopped while it runs\n" +
			"(--keep-apps-running leaves them running).\n\n" +
			"The token needs the data:transfer ability.",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			path := args[1]
			info, err := os.Stat(path)
			if err != nil {
				return exitcode.Usagef("can't read %s: %v", path, errors.Unwrap(err))
			}
			if !info.Mode().IsRegular() || info.Size() == 0 {
				return exitcode.Usagef("%s is not a non-empty file", path)
			}
			s, t, err := a.dataTarget(ctx, args[0])
			if err != nil {
				return err
			}
			p := a.Printer
			confirmation := ""
			if replace {
				if confirmation, err = a.confirmReplace(t.Service, yes); err != nil {
					return err
				}
			}
			tr, err := a.upload(ctx, s, t, path, info, "database_import", "")
			if err != nil {
				return err
			}
			run, err := s.Client.StartImport(ctx, t.Environment.ID, t.Service.ID, api.ImportRequest{
				Transfer: tr.ID, Replace: replace, StopDependents: !keepRunning, Confirmation: confirmation,
			})
			if err != nil {
				var apiErr *api.Error
				if !replace && errors.As(err, &apiErr) && strings.Contains(strings.ToLower(apiErr.Message), "empty") {
					p.Info("%s", p.Dim("Pass --replace to replace what's in the database (a safety snapshot is taken first)."))
				}
				return err
			}
			a.forgetUpload(path, info)
			return a.followImport(ctx, s, run)
		},
	}
	cmd.Flags().BoolVar(&replace, "replace", false, "replace everything in the database (safety snapshot first)")
	cmd.Flags().BoolVar(&keepRunning, "keep-apps-running", false, "don't stop the apps that use the database while importing")
	cmd.Flags().BoolVar(&yes, "yes", false, "don't ask before replacing")
	return cmd
}

// confirmReplace returns the confirmation text (the service's slug): typed
// on a terminal, or taken as given with --yes. Without a terminal and
// without --yes it refuses.
func (a *App) confirmReplace(svc *api.Service, yes bool) (string, error) {
	if yes {
		return svc.Slug, nil
	}
	if !a.Printer.TTY || a.Printer.JSON {
		return "", exitcode.Usagef("replacing %s's data needs a confirmation: pass --yes (there is no terminal to ask on)", svc.Name)
	}
	_, _ = fmt.Fprintf(a.Printer.Err, "This replaces everything in %s. A safety snapshot is taken first.\nType %s to confirm: ", a.Printer.Bold(svc.Name), a.Printer.Bold(svc.Slug))
	ans, err := a.readLine()
	if err != nil {
		return "", err
	}
	if ans != svc.Slug {
		return "", exitcode.New(exitcode.Usage, errors.New("the confirmation didn't match; nothing was changed"))
	}
	return ans, nil
}

// ---- uploads ----

// uploadState remembers transfers per local file so an upload resumes.
type uploadState struct {
	Uploads map[string]uploadEntry `json:"uploads"`
}

type uploadEntry struct {
	URL      string `json:"url"`
	Transfer string `json:"transfer"`
}

func uploadKey(path string, info fs.FileInfo) string {
	abs, err := filepath.Abs(path)
	if err != nil {
		abs = path
	}
	return fmt.Sprintf("%s|%d|%d", abs, info.Size(), info.ModTime().UnixNano())
}

func (a *App) uploadsFile() string { return filepath.Join(a.ConfigDir, "uploads.json") }

func (a *App) loadUploads() uploadState {
	st := uploadState{Uploads: map[string]uploadEntry{}}
	raw, err := os.ReadFile(a.uploadsFile())
	if err == nil {
		_ = json.Unmarshal(raw, &st)
	}
	if st.Uploads == nil {
		st.Uploads = map[string]uploadEntry{}
	}
	return st
}

func (a *App) saveUploads(st uploadState) {
	if err := os.MkdirAll(a.ConfigDir, 0o700); err != nil {
		return
	}
	raw, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return
	}
	tmp := a.uploadsFile() + ".tmp"
	if err := os.WriteFile(tmp, raw, 0o600); err == nil {
		_ = os.Rename(tmp, a.uploadsFile())
	}
}

func (a *App) forgetUpload(path string, info fs.FileInfo) {
	st := a.loadUploads()
	delete(st.Uploads, uploadKey(path, info))
	a.saveUploads(st)
}

// upload sends the file in chunks (resuming a transfer started earlier for
// the same file) and completes it.
func (a *App) upload(ctx context.Context, s *session, t *target, path string, info fs.FileInfo, purpose, volume string) (api.Transfer, error) {
	p := a.Printer
	key := uploadKey(path, info)
	st := a.loadUploads()
	var tr api.Transfer
	resumed := false
	if e, ok := st.Uploads[key]; ok && e.URL == s.URL {
		got, err := s.Client.Transfer(ctx, e.Transfer)
		switch {
		case err == nil && got.SizeBytes == info.Size() && (got.Status == "receiving" || got.Status == "ready"):
			tr, resumed = got, true
		case err != nil && !api.IsStatus(err, 404) && !api.IsStatus(err, 410):
			return api.Transfer{}, err
		}
	}
	if !resumed {
		created, err := s.Client.CreateTransfer(ctx, t.Environment.ID, t.Service.ID, api.CreateTransferRequest{
			Purpose: purpose, FileName: filepath.Base(path), SizeBytes: info.Size(), Volume: volume,
		})
		if err != nil {
			return api.Transfer{}, err
		}
		tr = created
		st.Uploads[key] = uploadEntry{URL: s.URL, Transfer: tr.ID}
		a.saveUploads(st)
	}
	if tr.Status == "ready" {
		if !p.JSON {
			p.Info("%s was uploaded already.", filepath.Base(path))
		}
		return tr, nil
	}
	if tr.ChunkBytes <= 0 || tr.Chunks <= 0 {
		return api.Transfer{}, &api.Error{Code: "bad_response", Message: "the dashboard answered without a chunk size"}
	}
	have := map[int]bool{}
	for _, i := range tr.ReceivedChunks {
		have[i] = true
	}
	f, err := os.Open(path) //nolint:gosec // the user names the file to upload
	if err != nil {
		return api.Transfer{}, err
	}
	defer func() { _ = f.Close() }()

	prog := newProgress(p, "Uploading "+filepath.Base(path), info.Size())
	var done int64
	for i := 0; i < tr.Chunks; i++ {
		size := min(tr.ChunkBytes, info.Size()-int64(i)*tr.ChunkBytes)
		if have[i] {
			done += size
			continue
		}
		if done > 0 && prog.start.IsZero() {
			prog.skipped = done
		}
		buf := make([]byte, size)
		if _, err := f.ReadAt(buf, int64(i)*tr.ChunkBytes); err != nil && !errors.Is(err, io.EOF) {
			return api.Transfer{}, err
		}
		if _, err := s.Client.PutChunk(ctx, tr.ID, i, buf); err != nil {
			prog.stop()
			if !p.JSON {
				p.Info("%s", p.Dim("Run the same command again to continue the upload."))
			}
			return api.Transfer{}, err
		}
		done += size
		prog.update(done)
	}
	prog.finish(done)
	if resumed && !p.JSON && len(have) > 0 {
		p.Info("%s", p.Dim(fmt.Sprintf("Continued an earlier upload (%d of %d parts were there already).", len(have), tr.Chunks)))
	}
	ready, err := s.Client.CompleteTransfer(ctx, tr.ID)
	if err != nil {
		if api.IsStatus(err, 422) {
			a.forgetUpload(path, info)
		}
		return api.Transfer{}, err
	}
	if !p.JSON {
		detail := ""
		if ready.Detail != nil {
			detail = *ready.Detail
		} else if ready.Format != nil {
			detail = *ready.Format
		}
		p.Info("%s Uploaded %s %s", p.Good("✓"), filepath.Base(path), p.Dim(detail))
	}
	return ready, nil
}

// progress prints transfer progress on stderr: one updating line on a
// terminal, else a line every 10 s. Nothing in --json mode.
type progress struct {
	label   string
	total   int64
	tty     bool
	quiet   bool
	start   time.Time
	skipped int64
	last    time.Time
	err     io.Writer
}

func newProgress(p *output.Printer, label string, total int64) *progress {
	return &progress{label: label, total: total, tty: p.Color, quiet: p.JSON, err: p.Err}
}

func (g *progress) update(done int64) {
	if g.quiet {
		return
	}
	now := time.Now()
	if g.start.IsZero() {
		g.start = now
	}
	every := 10 * time.Second
	if g.tty {
		every = 250 * time.Millisecond
	}
	if now.Sub(g.last) < every && done < g.total {
		return
	}
	g.last = now
	line := g.label + ": " + humanBytes(done)
	if g.total > 0 {
		line += " of " + humanBytes(g.total)
	}
	if el := now.Sub(g.start).Seconds(); el > 0.5 && done > g.skipped {
		rate := float64(done-g.skipped) / el
		line += fmt.Sprintf(" · %s/s", humanBytes(int64(rate)))
		if g.total > done && rate > 0 {
			line += " · " + shortDuration(time.Duration(float64(g.total-done)/rate*float64(time.Second))) + " left"
		}
	}
	if g.tty {
		_, _ = fmt.Fprintf(g.err, "\r\x1b[K%s", line)
		return
	}
	_, _ = fmt.Fprintln(g.err, line)
}

func (g *progress) stop() {
	if !g.quiet && g.tty && !g.last.IsZero() {
		_, _ = fmt.Fprintln(g.err)
	}
}

func (g *progress) finish(done int64) {
	if g.quiet {
		return
	}
	if g.tty && !g.last.IsZero() {
		_, _ = fmt.Fprint(g.err, "\r\x1b[K")
	}
	_ = done
}

// ---- imports ----

var importSteps = map[string]string{
	"waiting_for_safety_backup": "Taking a safety snapshot",
	"holding":                   "Stopping the apps that use it",
	"importing":                 "Importing",
}

func (a *App) followImport(ctx context.Context, s *session, run api.ImportRun) error {
	p := a.Printer
	lastLine := ""
	for {
		if !p.JSON && !run.Finished() {
			line := importSteps[run.Status]
			if line == "" {
				line = words(run.Status)
			}
			if run.Step != nil && *run.Step != "" {
				line += ": " + *run.Step
			}
			if line != lastLine {
				p.Info("%s %s", p.Warn("◌"), line)
				lastLine = line
			}
		}
		if run.Finished() {
			break
		}
		if err := dataSleep(ctx, dataPollEvery); err != nil {
			return err
		}
		next, err := s.Client.Import(ctx, run.ID)
		if err != nil {
			return err
		}
		run = next
	}
	if err := p.Result(run, func(w io.Writer) { printImportOutcome(a, w, run) }); err != nil {
		return err
	}
	if run.Status != "succeeded" {
		msg := "the import " + words(run.Status)
		if run.Error != nil && *run.Error != "" {
			msg = *run.Error
		}
		if !p.JSON {
			return &childExit{exitcode.TransferFailed} // the outcome above says why
		}
		return exitcode.New(exitcode.TransferFailed, errors.New(msg))
	}
	return nil
}

// words turns a status into words ("waiting_for_target" → "waiting for target").
func words(s string) string { return strings.ReplaceAll(s, "_", " ") }

func printImportOutcome(a *App, w io.Writer, run api.ImportRun) {
	p := a.Printer
	switch run.Status {
	case "succeeded":
		line := "Imported"
		if r := run.Result; r != nil {
			switch run.Subject {
			case "volume":
				line += fmt.Sprintf(" %d %s", r.Files, plural(int(r.Files), "file", "files"))
			default:
				noun := "tables"
				if r.Tables == 1 {
					noun = "table"
				}
				line += fmt.Sprintf(" %d %s", r.Tables, noun)
			}
			if r.SizeBytes > 0 {
				line += " · " + humanBytes(r.SizeBytes)
			}
			if r.DurationMs > 0 {
				line += " · in " + shortDuration(time.Duration(r.DurationMs)*time.Millisecond)
			}
		}
		_, _ = fmt.Fprintf(w, "%s %s\n", p.Good("✓"), line)
		if r := run.Result; r != nil {
			for _, warn := range r.Warnings {
				_, _ = fmt.Fprintf(w, "  %s %s\n", p.Warn("!"), warn)
			}
		}
	default:
		msg := "The import " + words(run.Status) + "."
		if run.Error != nil && *run.Error != "" {
			msg = *run.Error
		}
		_, _ = fmt.Fprintf(w, "%s %s\n", p.Bad("✕"), msg)
	}
}

// ---- exports ----

func (a *App) dbExportCmd() *cobra.Command {
	var out, snapshot string
	var force bool
	cmd := &cobra.Command{
		Use:   "export <service>",
		Short: "Download a database's data in its own format",
		Long: "Downloads a fresh dump of the database (pg_dump -Fc, gzipped SQL, mongodump archive), or with --backup a decrypted backup.\n\n" +
			"The file is written to <name>.part and renamed once it is complete. The token needs the data:transfer ability.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			s, t, err := a.dataTarget(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return a.export(cmd.Context(), s, t, api.ExportRequest{Snapshot: snapshot}, out, force)
		},
	}
	cmd.Flags().StringVarP(&out, "output", "o", "", "file to write (default: the name the dashboard suggests; - for stdout)")
	cmd.Flags().StringVar(&snapshot, "backup", "", "download this backup snapshot instead of the current data")
	cmd.Flags().BoolVar(&force, "force", false, "overwrite an existing file")
	return cmd
}

func (a *App) volumeExportCmd() *cobra.Command {
	var out, snapshot string
	var force bool
	cmd := &cobra.Command{
		Use:   "export <service> <mount path | vol_…>",
		Short: "Download a volume's files as a .tar.gz",
		Long: "Downloads the volume's files (owners and modes kept) as a .tar.gz, or with --backup a decrypted volume backup.\n\n" +
			"The file is written to <name>.part and renamed once it is complete. The token needs the data:transfer ability.",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			s, t, err := a.dataTarget(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			vol, err := volumeID(t.Service, args[1])
			if err != nil {
				return err
			}
			return a.export(cmd.Context(), s, t, api.ExportRequest{Volume: vol, Snapshot: snapshot}, out, force)
		},
	}
	cmd.Flags().StringVarP(&out, "output", "o", "", "file to write (default: the name the dashboard suggests; - for stdout)")
	cmd.Flags().StringVar(&snapshot, "backup", "", "download this backup snapshot instead of the current files")
	cmd.Flags().BoolVar(&force, "force", false, "overwrite an existing file")
	return cmd
}

// volumeID resolves a volume by id or by its mount path (when the service
// lists its volumes).
func volumeID(svc *api.Service, ref string) (string, error) {
	if strings.HasPrefix(ref, "vol_") {
		return ref, nil
	}
	clean := "/" + strings.Trim(ref, "/")
	for _, v := range svc.Volumes {
		if v.MountPath == clean || v.MountPath == ref {
			return v.ID, nil
		}
	}
	if len(svc.Volumes) == 0 {
		return "", exitcode.Usagef("pass the volume id (vol_…) shown in the dashboard's Backups tab")
	}
	paths := make([]string, len(svc.Volumes))
	for i, v := range svc.Volumes {
		paths[i] = v.MountPath
	}
	return "", exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no volume at %s (it has %s)", svc.Name, ref, strings.Join(paths, ", ")))
}

func (a *App) export(ctx context.Context, s *session, t *target, req api.ExportRequest, out string, force bool) error {
	p := a.Printer
	if out == "-" && p.JSON {
		return exitcode.Usagef("--json and -o - both write to stdout; pick one")
	}
	if out != "" && out != "-" && !force {
		if _, err := os.Stat(out); err == nil {
			return exitcode.Usagef("%s exists; pass --force to overwrite it", out)
		}
	}
	run, err := s.Client.StartExport(ctx, t.Environment.ID, t.Service.ID, req)
	if err != nil {
		return err
	}
	shown := ""
	for run.Status == "preparing" {
		if !p.JSON {
			line := "Preparing the download"
			if run.SizeBytes > 0 {
				line += fmt.Sprintf(" · %s so far", humanBytes(run.SizeBytes))
			}
			if line != shown {
				p.Info("%s %s", p.Warn("◌"), line)
				shown = line
			}
		}
		if err := dataSleep(ctx, dataPollEvery); err != nil {
			return err
		}
		if run, err = s.Client.Export(ctx, run.ID); err != nil {
			return err
		}
	}
	if run.Status != "ready" {
		msg := "the export " + words(run.Status)
		if run.Error != nil && *run.Error != "" {
			msg = *run.Error
		}
		if p.JSON {
			_ = p.Result(run, nil)
		}
		return exitcode.New(exitcode.TransferFailed, errors.New(msg))
	}
	link, err := s.Client.ExportLink(ctx, run.ID)
	if err != nil {
		return err
	}
	dl, err := s.Client.OpenDownload(ctx, link.URL)
	if err != nil {
		return err
	}
	defer func() { _ = dl.Body.Close() }()
	want := run.SizeBytes
	if dl.Size >= 0 && want > 0 && dl.Size != want {
		return exitcode.New(exitcode.TransferFailed, fmt.Errorf("the download is %d bytes but the export has %d; nothing was written", dl.Size, want))
	}
	if dl.Size >= 0 {
		want = dl.Size
	}
	if out == "" {
		out = dl.FileName
		if out == "" {
			out = api.AttachmentName(`filename="` + run.FileName + `"`)
		}
		if out == "" {
			out = run.ID
		}
		if wd, err := a.Getwd(); err == nil {
			out = filepath.Join(wd, out)
		}
		if !force {
			if _, err := os.Stat(out); err == nil {
				return exitcode.Usagef("%s exists; pass -o to pick another name or --force to overwrite it", out)
			}
		}
	}
	if out == "-" {
		n, err := io.Copy(p.Out, dl.Body)
		if err != nil {
			return err
		}
		if want > 0 && n != want {
			return exitcode.New(exitcode.TransferFailed, fmt.Errorf("the download ended after %d of %d bytes", n, want))
		}
		return nil
	}
	n, err := writeDownload(ctx, out, dl.Body, want, newProgress(p, "Downloading "+filepath.Base(out), want))
	if err != nil {
		return err
	}
	result := struct {
		api.ExportRun
		Path string `json:"path"`
	}{run, out}
	return p.Result(result, func(w io.Writer) {
		_, _ = fmt.Fprintf(w, "%s Saved %s %s\n", p.Good("✓"), out, p.Dim("("+humanBytes(n)+")"))
	})
}

// writeDownload writes body to path+".part" and renames it once exactly
// want bytes (when known) arrived; a short or long download leaves nothing.
func writeDownload(ctx context.Context, path string, body io.Reader, want int64, prog *progress) (int64, error) {
	part := path + ".part"
	f, err := os.OpenFile(part, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600) //nolint:gosec // the user picks the output path
	if err != nil {
		return 0, err
	}
	fail := func(err error) (int64, error) {
		_ = f.Close()
		_ = os.Remove(part)
		prog.stop()
		return 0, err
	}
	buf := make([]byte, 1<<20)
	var n int64
	for {
		if err := ctx.Err(); err != nil {
			return fail(err)
		}
		k, rerr := body.Read(buf)
		if k > 0 {
			if _, err := f.Write(buf[:k]); err != nil {
				return fail(err)
			}
			n += int64(k)
			prog.update(n)
		}
		if errors.Is(rerr, io.EOF) {
			break
		}
		if rerr != nil {
			return fail(fmt.Errorf("the download broke off after %s: %w (run the command again)", humanBytes(n), rerr))
		}
	}
	if want > 0 && n != want {
		return fail(exitcode.New(exitcode.TransferFailed, fmt.Errorf("the download ended after %d of %d bytes; nothing was written", n, want)))
	}
	if err := f.Sync(); err != nil {
		return fail(err)
	}
	if err := f.Close(); err != nil {
		_ = os.Remove(part)
		return 0, err
	}
	prog.finish(n)
	if err := os.Rename(part, path); err != nil {
		_ = os.Remove(part)
		return 0, err
	}
	return n, nil
}

// ---- formatting ----

func humanBytes(n int64) string {
	const unit = 1000
	if n < unit {
		return fmt.Sprintf("%d B", n)
	}
	div, exp := int64(unit), 0
	for m := n / unit; m >= unit; m /= unit {
		div *= unit
		exp++
	}
	v := float64(n) / float64(div)
	if v < 10 {
		return fmt.Sprintf("%.1f %cB", v, "kMGTPE"[exp])
	}
	return fmt.Sprintf("%.0f %cB", v, "kMGTPE"[exp])
}

func shortDuration(d time.Duration) string {
	d = d.Round(time.Second)
	if d < time.Minute {
		return fmt.Sprintf("%ds", int(d.Seconds()))
	}
	if d < time.Hour {
		return fmt.Sprintf("%dm %ds", int(d.Minutes()), int(d.Seconds())%60)
	}
	return fmt.Sprintf("%dh %dm", int(d.Hours()), int(d.Minutes())%60)
}
