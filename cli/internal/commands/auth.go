package commands

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/config"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// loginResult is `login --json`'s stdout document.
type loginResult struct {
	URL       string   `json:"url"`
	User      api.User `json:"user"`
	Team      api.Team `json:"team"`
	Abilities []string `json:"abilities"`
	ExpiresAt string   `json:"expiresAt"`
	Store     string   `json:"store"`
}

// loginPending is the one-line JSON `login --json` prints on stderr while
// waiting for approval (machine-readable user code).
type loginPending struct {
	Event                   string `json:"event"`
	UserCode                string `json:"userCode"`
	VerificationURL         string `json:"verificationUrl"`
	VerificationURLComplete string `json:"verificationUrlComplete"`
	ExpiresIn               int    `json:"expiresIn"`
}

// loginClient is the unauthenticated client of the sign-in flow.
type loginClient = api.Client

// newLoginClient is replaced in tests (instant polling).
var newLoginClient = func(url, userAgent string) *loginClient { return api.New(url, "", userAgent) }

func (a *App) loginCmd() *cobra.Command {
	var rawURL string
	var noBrowser bool
	cmd := &cobra.Command{
		Use:   "login",
		Short: "Sign in through the dashboard (opens your browser)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			u := rawURL
			if u == "" {
				var err error
				if u, err = a.dashboardURL(); err != nil {
					return exitcode.Usagef("which launchroom? pass --url https://your-launchroom")
				}
			}
			u, err := config.NormalizeURL(u)
			if err != nil {
				return exitcode.New(exitcode.Usage, err)
			}
			host, _ := a.Hostname()
			client := newLoginClient(u, a.UserAgent())
			sess, err := client.StartLogin(ctx, host, a.Version)
			if err != nil {
				return err
			}
			p := a.Printer
			if p.JSON {
				b, _ := json.Marshal(loginPending{"pending", sess.UserCode, sess.VerificationURL, sess.VerificationURLComplete, sess.ExpiresIn})
				_, _ = fmt.Fprintln(p.Err, string(b))
			} else {
				p.Info("Your sign-in code: %s", p.Bold(sess.UserCode))
				p.Info("Confirm it matches in the browser: %s", sess.VerificationURLComplete)
			}
			if !noBrowser && a.OpenBrowser != nil {
				if err := a.OpenBrowser(sess.VerificationURLComplete); err != nil && !p.JSON {
					p.Info("Couldn't open a browser; open the link above yourself.")
				}
			}
			if !p.JSON {
				p.Info(p.Dim("Waiting for approval…"))
			}
			tok, err := pollLogin(ctx, client, sess)
			if err != nil {
				return err
			}
			store, err := a.credentials().Save(u, tok.Token)
			if err != nil {
				return fmt.Errorf("storing the token: %w", err)
			}
			cfg, err := config.Load(a.ConfigDir)
			if err != nil {
				return err
			}
			cfg.Current = u
			cfg.Hosts[u] = config.Host{Store: store, User: tok.User.Email, Team: tok.Team.Name}
			if err := cfg.Save(a.ConfigDir); err != nil {
				return err
			}
			res := loginResult{URL: u, User: tok.User, Team: tok.Team, Abilities: tok.Abilities, ExpiresAt: tok.ExpiresAt, Store: store}
			return p.Result(res, func(w io.Writer) {
				where := "the system keychain"
				if store == config.StoreFile {
					where = a.ConfigDir + "/credentials.json"
				}
				_, _ = fmt.Fprintf(w, "%s Signed in as %s to %s (team %s).\n", p.Good("✓"), tok.User.Email, u, tok.Team.Name)
				_, _ = fmt.Fprintf(w, "  Token stored in %s, expires %s.\n", where, shortTime(tok.ExpiresAt))
				_, _ = fmt.Fprintln(w, "  Next: `launchroom link` in your project directory.")
			})
		},
	}
	cmd.Flags().StringVar(&rawURL, "url", "", "dashboard URL, e.g. https://launchroom.example.com")
	cmd.Flags().BoolVar(&noBrowser, "no-browser", false, "don't open a browser; print the link only")
	return cmd
}

// pollLogin waits for the dashboard to approve the sign-in (RFC 8628 style).
func pollLogin(ctx context.Context, client *api.Client, sess api.LoginSession) (api.LoginToken, error) {
	interval := time.Duration(sess.Interval) * time.Second
	if interval < time.Second {
		interval = 2 * time.Second
	}
	expires := sess.ExpiresIn
	if expires <= 0 {
		expires = 600
	}
	deadline := time.Now().Add(time.Duration(expires) * time.Second)
	for {
		if err := client.Sleep(ctx, interval); err != nil {
			return api.LoginToken{}, err
		}
		tok, err := client.PollLogin(ctx, sess.DeviceCode)
		var apiErr *api.Error
		switch {
		case err == nil:
			return tok, nil
		case errors.As(err, &apiErr) && apiErr.Status == http.StatusPreconditionRequired:
			// authorization_pending
		case errors.As(err, &apiErr) && apiErr.Status == http.StatusTooManyRequests:
			interval += 2 * time.Second
		case errors.As(err, &apiErr) && apiErr.Status == http.StatusForbidden:
			return api.LoginToken{}, exitcode.New(exitcode.NotAllowed, errors.New("the sign-in was denied in the dashboard"))
		case errors.As(err, &apiErr) && apiErr.Status == http.StatusGone:
			return api.LoginToken{}, exitcode.New(exitcode.Refused, errors.New("the sign-in request expired; run `launchroom login` again"))
		case errors.As(err, &apiErr) && apiErr.Status == 0:
			// Transient network trouble: keep polling until the deadline.
		default:
			return api.LoginToken{}, err
		}
		if time.Now().After(deadline) {
			return api.LoginToken{}, exitcode.New(exitcode.Refused, errors.New("the sign-in request expired; run `launchroom login` again"))
		}
	}
}

func (a *App) logoutCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "logout",
		Short: "Forget the stored token for this dashboard",
		Args:  cobra.NoArgs,
		RunE: func(*cobra.Command, []string) error {
			u, err := a.dashboardURL()
			if err != nil {
				return err
			}
			if err := a.credentials().Delete(u); err != nil {
				return err
			}
			cfg, err := config.Load(a.ConfigDir)
			if err != nil {
				return err
			}
			delete(cfg.Hosts, u)
			if cfg.Current == u {
				cfg.Current = ""
			}
			if err := cfg.Save(a.ConfigDir); err != nil {
				return err
			}
			return a.Printer.Result(map[string]any{"url": u, "signedOut": true}, func(w io.Writer) {
				_, _ = fmt.Fprintf(w, "Signed out of %s. The token stays valid until it expires; revoke it in Settings → API tokens.\n", u)
			})
		},
	}
}

func (a *App) whoamiCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "whoami",
		Short: "Show the signed-in user, team and token",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			s, err := a.session()
			if err != nil {
				return err
			}
			me, err := s.Client.Me(cmd.Context())
			if err != nil {
				return err
			}
			out := struct {
				URL string `json:"url"`
				api.Me
			}{s.URL, me}
			return a.Printer.Result(out, func(w io.Writer) {
				p := a.Printer
				_, _ = fmt.Fprintf(w, "%s %s\n", p.Bold(me.User.Name), p.Dim("<"+me.User.Email+">"))
				_, _ = fmt.Fprintf(w, "Team       %s (%s)\n", me.Team.Name, me.Team.Role)
				_, _ = fmt.Fprintf(w, "Dashboard  %s\n", s.URL)
				_, _ = fmt.Fprintf(w, "Token      %s · %s\n", me.Token.Name, strings.Join(me.Token.Abilities, ", "))
				_, _ = fmt.Fprintf(w, "Expires    %s\n", shortTime(me.Token.ExpiresAt))
			})
		},
	}
}

// shortTime renders an ISO time as "2026-11-18 14:02 UTC" (or as given).
func shortTime(iso string) string {
	t, err := time.Parse(time.RFC3339, iso)
	if err != nil {
		return iso
	}
	return t.UTC().Format("2006-01-02 15:04 UTC")
}
