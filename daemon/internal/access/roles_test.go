package access

import (
	"context"
	"crypto/hmac"
	"crypto/pbkdf2"
	"crypto/sha256"
	"encoding/base64"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestQuoting(t *testing.T) {
	for in, want := range map[string]string{
		`lr_tmp_abc`:          `"lr_tmp_abc"`,
		`a"; DROP ROLE x; --`: `"a""; DROP ROLE x; --"`,
	} {
		if got, err := QuoteIdent(in); err != nil || got != want {
			t.Errorf("QuoteIdent(%q) = %q, %v", in, got, err)
		}
	}
	for _, bad := range []string{"", "a\x00b", "a\nb", strings.Repeat("a", 64)} {
		if _, err := QuoteIdent(bad); err == nil {
			t.Errorf("QuoteIdent(%q) accepted", bad)
		}
	}
	if got, err := QuoteLiteral(`it's'; DROP TABLE x; --`); err != nil || got != `'it''s''; DROP TABLE x; --'` {
		t.Errorf("QuoteLiteral = %q, %v", got, err)
	}
	for _, bad := range []string{`a\'b`, "a\x00", "a\nb"} {
		if _, err := QuoteLiteral(bad); err == nil {
			t.Errorf("QuoteLiteral(%q) accepted", bad)
		}
	}
}

func TestScramVerifier(t *testing.T) {
	salt := []byte("0123456789abcdef")
	v, err := ScramVerifier("pencil", salt)
	if err != nil {
		t.Fatal(err)
	}
	if !reVerifier.MatchString(v) {
		t.Fatalf("verifier %q has the wrong shape", v)
	}
	// A client proving "pencil" (RFC 5802) must match the stored key.
	salted, _ := pbkdf2.Key(sha256.New, "pencil", salt, 4096, 32)
	m := hmac.New(sha256.New, salted)
	m.Write([]byte("Client Key"))
	stored := sha256.Sum256(m.Sum(nil))
	if !strings.Contains(v, "$"+base64.StdEncoding.EncodeToString(stored[:])+":") {
		t.Fatal("stored key doesn't match the password")
	}
	if strings.Contains(v, "pencil") {
		t.Fatal("the verifier contains the password")
	}
	if _, err := ScramVerifier("x", []byte("short")); err == nil {
		t.Fatal("short salt accepted")
	}
}

func TestPostgresRoleSQL(t *testing.T) {
	v, _ := ScramVerifier("secret-password-123456789012", []byte("0123456789abcdef"))
	r := PostgresRole{Role: "lr_tmp_abcdef012345", Database: "app", Owner: "app", Verifier: v, Until: time.Date(2026, 10, 25, 12, 0, 0, 0, time.UTC)}
	sql, err := r.CreateSQL()
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		`CREATE ROLE "lr_tmp_abcdef012345" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS`,
		`PASSWORD 'SCRAM-SHA-256$4096:`, `VALID UNTIL '2026-10-25 12:00:00+00'`,
		`GRANT CONNECT ON DATABASE "app" TO "lr_tmp_abcdef012345"`,
		`SET default_transaction_read_only = on`, `GRANT pg_read_all_data TO %I`,
	} {
		if !strings.Contains(sql, want) {
			t.Errorf("create SQL lacks %q:\n%s", want, sql)
		}
	}
	if strings.Contains(sql, "secret-password") {
		t.Fatal("plaintext password in SQL")
	}
	if !strings.Contains(sql, "w boolean := false") {
		t.Fatal("read-only role grants writes")
	}
	r.Writes = true
	sql, _ = r.CreateSQL()
	if !strings.Contains(sql, "w boolean := true") || strings.Contains(sql, "default_transaction_read_only") {
		t.Fatalf("writes role:\n%s", sql)
	}
	drop, err := r.DropSQL()
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{`pg_terminate_backend`, `REASSIGN OWNED BY ' || '"lr_tmp_abcdef012345"' || ' TO ' || '"app"'`, `DROP ROLE IF EXISTS "lr_tmp_abcdef012345"`} {
		if !strings.Contains(drop, want) {
			t.Errorf("drop SQL lacks %q:\n%s", want, drop)
		}
	}
	// Names that aren't generated or identifiers from env never reach SQL.
	for _, bad := range []PostgresRole{
		{Role: `lr_tmp_abc"; DROP`, Database: "app", Owner: "app", Verifier: v},
		{Role: "lr_tmp_ABCDEF012345", Database: "app", Owner: "app", Verifier: v},
		{Role: "lr_tmp_abcdef012345", Database: `app"--`, Owner: "app", Verifier: v},
		{Role: "lr_tmp_abcdef012345", Database: "app", Owner: "app", Verifier: "plaintext'pw"},
	} {
		if _, err := bad.CreateSQL(); err == nil {
			t.Errorf("CreateSQL accepted %+v", bad)
		}
	}
}

func TestRedisRoleArgs(t *testing.T) {
	r := RedisRole{User: "lr_tmp_abcdef012345", Password: "pw-0123456789abcdefghijkl"}
	args, err := r.SetUserArgs()
	if err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(args, " ")
	if strings.Contains(joined, r.Password) {
		t.Fatal("password in argv")
	}
	if !strings.Contains(joined, "reset on #") || !strings.Contains(joined, "-@all +@read -@dangerous") || strings.Contains(joined, "+@write") {
		t.Fatalf("read-only rules: %s", joined)
	}
	r.Writes = true
	args, _ = r.SetUserArgs()
	if j := strings.Join(args, " "); !strings.Contains(j, "+@write") || !strings.HasSuffix(j, "-@dangerous") {
		t.Fatalf("write rules: %s", j)
	}
	if _, err := (RedisRole{User: "default", Password: "x"}).SetUserArgs(); err == nil {
		t.Fatal("a non-generated user was accepted")
	}
}

func TestCreateAndDropRoleRunInTheContainer(t *testing.T) {
	rec := &envRecorder{}
	env := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "shop", "POSTGRES_PASSWORD": "service-pw-xyz"}
	info, err := CreateRole(context.Background(), rec, state.EnginePostgres, env, "lr_tmp_abcdef012345", "temp-password-0123456789ab", time.Now().Add(time.Hour), false)
	if err != nil || info.Database != "shop" {
		t.Fatalf("create: %v %+v", err, info)
	}
	c := rec.calls[0]
	if c.argv[0] != "psql" || !strings.Contains(strings.Join(c.argv, " "), "-f -") {
		t.Fatalf("argv %q", c.argv)
	}
	for _, a := range c.argv {
		if strings.Contains(a, "service-pw") || strings.Contains(a, "temp-password") {
			t.Fatalf("a password in argv: %q", c.argv)
		}
	}
	if strings.Contains(c.stdin, "temp-password") || !strings.Contains(strings.Join(c.env, " "), "PGPASSWORD=service-pw-xyz") {
		t.Fatalf("stdin or env wrong: %q / %q", c.stdin, c.env)
	}
	if err := DropRole(context.Background(), rec, state.EnginePostgres, env, "lr_tmp_abcdef012345"); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(rec.calls[1].stdin, "DROP ROLE IF EXISTS") {
		t.Fatal("drop didn't drop")
	}

	redis := &envRecorder{}
	renv := map[string]secret.Secret{"REDIS_PASSWORD": "service-pw"}
	if _, err := CreateRole(context.Background(), redis, state.EngineValkey, renv, "lr_tmp_abcdef012345", "temp-password-0123456789ab", time.Now(), true); err != nil {
		t.Fatal(err)
	}
	if redis.calls[0].argv[0] != "valkey-cli" || redis.calls[0].env[0] != "REDISCLI_AUTH=service-pw" {
		t.Fatalf("redis call %+v", redis.calls[0])
	}
	failing := &envRecorder{reply: func([]string) (int, string, string) { return 0, "(error) ERR unknown command 'ACL'", "" }}
	if _, err := CreateRole(context.Background(), failing, state.EngineRedis, renv, "lr_tmp_abcdef012345", "temp-password-0123456789ab", time.Now(), false); err == nil {
		t.Fatal("a server error was ignored")
	}
	if _, err := CreateRole(context.Background(), rec, state.EngineMySQL, env, "lr_tmp_abcdef012345", "x", time.Now(), false); err == nil {
		t.Fatal("mysql has no temporary access")
	}
}
