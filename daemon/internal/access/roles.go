package access

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/pbkdf2"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Temporary database roles (docs/03 §12.3). Everything a user influences
// is a generated name from a fixed alphabet; quoting is still applied and
// the grammar re-checked right before a statement is built.

var (
	reRoleName = regexp.MustCompile(`^lr_tmp_[a-z0-9]{12}$`)
	// reServiceIdent is the grammar of identifiers read from the
	// workload's env (the same as data sessions and backups).
	reServiceIdent = regexp.MustCompile(`^[A-Za-z0-9_]{1,63}$`)
	reVerifier     = regexp.MustCompile(`^SCRAM-SHA-256\$4096:[A-Za-z0-9+/=]{24}\$[A-Za-z0-9+/=]{44}:[A-Za-z0-9+/=]{44}$`)
)

// QuoteIdent double-quotes a Postgres identifier ("" doubled). It refuses
// NUL and control characters.
func QuoteIdent(s string) (string, error) {
	if s == "" || len(s) > 63 || strings.ContainsFunc(s, func(r rune) bool { return r < 0x20 || r == 0x7f }) {
		return "", fmt.Errorf("identifier %q can't be quoted", s)
	}
	return `"` + strings.ReplaceAll(s, `"`, `""`) + `"`, nil
}

// QuoteLiteral single-quotes a Postgres string literal (' doubled). It
// refuses NUL, control characters and backslashes (so the result is the
// same with and without standard_conforming_strings).
func QuoteLiteral(s string) (string, error) {
	if strings.ContainsFunc(s, func(r rune) bool { return r < 0x20 || r == 0x7f || r == '\\' }) {
		return "", errors.New("literal can't be quoted")
	}
	return "'" + strings.ReplaceAll(s, "'", "''") + "'", nil
}

// ScramVerifier returns the SCRAM-SHA-256 verifier Postgres stores for a
// password (RFC 5802/7677, 4096 iterations, 16-byte salt). Sending the
// verifier keeps the plain password out of the SQL and the server's logs.
func ScramVerifier(password string, salt []byte) (string, error) {
	if len(salt) != 16 {
		return "", errors.New("scram: salt must be 16 bytes")
	}
	salted, err := pbkdf2.Key(sha256.New, password, salt, 4096, 32)
	if err != nil {
		return "", err
	}
	mac := func(key []byte, msg string) []byte {
		h := hmac.New(sha256.New, key)
		h.Write([]byte(msg))
		return h.Sum(nil)
	}
	clientKey := mac(salted, "Client Key")
	stored := sha256.Sum256(clientKey)
	serverKey := mac(salted, "Server Key")
	b := base64.StdEncoding.EncodeToString
	return "SCRAM-SHA-256$4096:" + b(salt) + "$" + b(stored[:]) + ":" + b(serverKey), nil
}

// PostgresRole is the input of the role statements.
type PostgresRole struct {
	Role     string // lr_tmp_…
	Database string // the service's database
	Owner    string // the service's user (objects are reassigned to it)
	Verifier string // ScramVerifier output
	Until    time.Time
	Writes   bool
}

func (r PostgresRole) check() error {
	switch {
	case !reRoleName.MatchString(r.Role):
		return errors.New("postgres: role name is not generated")
	case !reServiceIdent.MatchString(r.Database) || !reServiceIdent.MatchString(r.Owner):
		return errors.New("postgres: database or user name is not a valid identifier")
	case r.Verifier != "" && !reVerifier.MatchString(r.Verifier):
		return errors.New("postgres: not a SCRAM verifier")
	}
	return nil
}

// CreateSQL is the script that creates the role (read on psql's stdin).
func (r PostgresRole) CreateSQL() (string, error) {
	if err := r.check(); err != nil {
		return "", err
	}
	role, _ := QuoteIdent(r.Role)
	db, _ := QuoteIdent(r.Database)
	pw, err := QuoteLiteral(r.Verifier)
	if err != nil {
		return "", err
	}
	until, _ := QuoteLiteral(r.Until.UTC().Format("2006-01-02 15:04:05+00"))
	// The DO block reads the role name only from a quoted literal and
	// builds its statements with format('%I').
	roleLit, _ := QuoteLiteral(r.Role)
	writes := "false"
	if r.Writes {
		writes = "true"
	}
	var b strings.Builder
	fmt.Fprintf(&b, "CREATE ROLE %s LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS INHERIT CONNECTION LIMIT 10 PASSWORD %s VALID UNTIL %s;\n", role, pw, until)
	fmt.Fprintf(&b, "GRANT CONNECT ON DATABASE %s TO %s;\n", db, role)
	if !r.Writes {
		fmt.Fprintf(&b, "ALTER ROLE %s SET default_transaction_read_only = on;\n", role)
	}
	fmt.Fprintf(&b, `DO $lr$
DECLARE
  r text := %s;
  w boolean := %s;
  s record;
BEGIN
  IF current_setting('server_version_num')::int >= 140000 THEN
    EXECUTE format('GRANT pg_read_all_data TO %%I', r);
    IF w THEN EXECUTE format('GRANT pg_write_all_data TO %%I', r); END IF;
  ELSE
    FOR s IN SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg\_%%' AND nspname <> 'information_schema' LOOP
      EXECUTE format('GRANT USAGE ON SCHEMA %%I TO %%I', s.nspname, r);
      EXECUTE format('GRANT SELECT ON ALL TABLES IN SCHEMA %%I TO %%I', s.nspname, r);
      EXECUTE format('GRANT SELECT ON ALL SEQUENCES IN SCHEMA %%I TO %%I', s.nspname, r);
      IF w THEN EXECUTE format('GRANT INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %%I TO %%I', s.nspname, r); END IF;
      IF w THEN EXECUTE format('GRANT USAGE ON ALL SEQUENCES IN SCHEMA %%I TO %%I', s.nspname, r); END IF;
    END LOOP;
  END IF;
END
$lr$;
`, roleLit, writes)
	return b.String(), nil
}

// DropSQL ends the role's connections, hands what it created to the
// service's user and drops it. It succeeds when the role is already gone.
func (r PostgresRole) DropSQL() (string, error) {
	if err := r.check(); err != nil {
		return "", err
	}
	role, _ := QuoteIdent(r.Role)
	owner, _ := QuoteIdent(r.Owner)
	roleLit, _ := QuoteLiteral(r.Role)
	return fmt.Sprintf(`SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = %s;
DO $lr$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = %s) THEN
    EXECUTE 'REASSIGN OWNED BY ' || %s || ' TO ' || %s;
    EXECUTE 'DROP OWNED BY ' || %s;
  END IF;
END
$lr$;
DROP ROLE IF EXISTS %s;
`, roleLit, roleLit, quoteForConcat(role), quoteForConcat(owner), quoteForConcat(role), role), nil
}

// quoteForConcat makes an already quoted identifier a literal for string
// concatenation inside the DO block.
func quoteForConcat(quotedIdent string) string {
	l, _ := QuoteLiteral(quotedIdent)
	return l
}

// RedisRole builds the ACL commands of a temporary Redis/Valkey user.
type RedisRole struct {
	User     string
	Password string
	Writes   bool
}

// redisReadRules and redisWriteRules are the user's permissions:
// everything else, and @dangerous always, is refused.
var (
	redisReadRules  = []string{"-@all", "+@read", "-@dangerous", "+ping", "+echo", "+select", "+hello", "+auth", "+quit"}
	redisWriteRules = []string{"+@write", "+multi", "+exec", "+discard", "+watch", "+unwatch", "-@dangerous"}
)

// SetUserArgs is the redis-cli argv tail for ACL SETUSER: the password only
// as its SHA-256 (#hex), so it is never in the container's process list.
func (r RedisRole) SetUserArgs() ([]string, error) {
	if !reRoleName.MatchString(r.User) {
		return nil, errors.New("redis: user name is not generated")
	}
	if r.Password == "" {
		return nil, errors.New("redis: no password")
	}
	sum := sha256.Sum256([]byte(r.Password))
	args := []string{"ACL", "SETUSER", r.User, "reset", "on", "#" + hex.EncodeToString(sum[:]), "~*", "&*"}
	args = append(args, redisReadRules...)
	if r.Writes {
		args = append(args, redisWriteRules...)
	}
	return args, nil
}

// DropArgs are the commands that end the user's connections and remove it.
func (r RedisRole) DropArgs() ([][]string, error) {
	if !reRoleName.MatchString(r.User) {
		return nil, errors.New("redis: user name is not generated")
	}
	return [][]string{{"CLIENT", "KILL", "USER", r.User}, {"ACL", "DELUSER", r.User}}, nil
}

// ---- running them in the database container ----

// identFromEnv reads an identifier from the workload's env.
func identFromEnv(env map[string]secret.Secret, def string, names ...string) (string, error) {
	v := def
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			v = s.Reveal()
			break
		}
	}
	if !reServiceIdent.MatchString(v) {
		return "", fmt.Errorf("%s in the database env is not a valid identifier", names[0])
	}
	return v, nil
}

func passwordFromEnv(env map[string]secret.Secret, names ...string) (string, error) {
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			return s.Reveal(), nil
		}
	}
	return "", fmt.Errorf("the database env has no %s", names[0])
}

// pgTarget is the service's own user and database, from its env.
type pgTarget struct{ user, db, password string }

func postgresTarget(env map[string]secret.Secret) (pgTarget, error) {
	user, err := identFromEnv(env, "postgres", "POSTGRES_USER")
	if err != nil {
		return pgTarget{}, err
	}
	db, err := identFromEnv(env, user, "POSTGRES_DB")
	if err != nil {
		return pgTarget{}, err
	}
	pw, err := passwordFromEnv(env, "POSTGRES_PASSWORD")
	if err != nil {
		return pgTarget{}, err
	}
	return pgTarget{user: user, db: db, password: pw}, nil
}

// redisCLI is the engine's client binary.
func redisCLI(engine state.DatabaseEngine) string {
	if engine == state.EngineValkey {
		return "valkey-cli"
	}
	return "redis-cli"
}

// redisAuthEnv is the service's own password (REDISCLI_AUTH), if any.
func redisAuthEnv(env map[string]secret.Secret) []string {
	for _, n := range []string{"REDIS_PASSWORD", "VALKEY_PASSWORD"} {
		if s := env[n]; !s.IsZero() {
			return []string{"REDISCLI_AUTH=" + s.Reveal()}
		}
	}
	return nil
}

// EnvRunner is a Runner that also takes extra environment entries for one
// process (credentials travel only in the exec env).
type EnvRunner interface {
	RunEnv(ctx context.Context, argv, env []string, stdin []byte, stdout *bytes.Buffer) (exitCode int, stderr string, err error)
}

// DatabaseInfo is what the proxy needs to know about the service.
type DatabaseInfo struct {
	// Database is the Postgres database the role may connect to.
	Database string
}

// CreateRole creates the temporary role. The caller has written the
// ledger entry first.
func CreateRole(ctx context.Context, r EnvRunner, engine state.DatabaseEngine, env map[string]secret.Secret, role, password string, until time.Time, writes bool) (DatabaseInfo, error) {
	switch engine {
	case state.EnginePostgres:
		t, err := postgresTarget(env)
		if err != nil {
			return DatabaseInfo{}, err
		}
		salt := make([]byte, 16)
		if _, err := rand.Read(salt); err != nil {
			return DatabaseInfo{}, err
		}
		v, err := ScramVerifier(password, salt)
		if err != nil {
			return DatabaseInfo{}, err
		}
		sql, err := PostgresRole{Role: role, Database: t.db, Owner: t.user, Verifier: v, Until: until, Writes: writes}.CreateSQL()
		if err != nil {
			return DatabaseInfo{}, err
		}
		if err := runPsql(ctx, r, t, sql); err != nil {
			return DatabaseInfo{}, err
		}
		return DatabaseInfo{Database: t.db}, nil
	case state.EngineRedis, state.EngineValkey:
		args, err := RedisRole{User: role, Password: password, Writes: writes}.SetUserArgs()
		if err != nil {
			return DatabaseInfo{}, err
		}
		if err := runRedis(ctx, r, engine, env, args); err != nil {
			return DatabaseInfo{}, err
		}
		return DatabaseInfo{}, nil
	}
	return DatabaseInfo{}, fmt.Errorf("%s has no temporary access", engine)
}

// DropRole removes the temporary role and ends its connections.
func DropRole(ctx context.Context, r EnvRunner, engine state.DatabaseEngine, env map[string]secret.Secret, role string) error {
	switch engine {
	case state.EnginePostgres:
		t, err := postgresTarget(env)
		if err != nil {
			return err
		}
		sql, err := PostgresRole{Role: role, Database: t.db, Owner: t.user}.DropSQL()
		if err != nil {
			return err
		}
		return runPsql(ctx, r, t, sql)
	case state.EngineRedis, state.EngineValkey:
		cmds, err := RedisRole{User: role, Password: "-"}.DropArgs()
		if err != nil {
			return err
		}
		// CLIENT KILL fails when no client is connected; only DELUSER counts.
		_ = runRedis(ctx, r, engine, env, cmds[0])
		return runRedis(ctx, r, engine, env, cmds[1])
	}
	return fmt.Errorf("%s has no temporary access", engine)
}

func runPsql(ctx context.Context, r EnvRunner, t pgTarget, sql string) error {
	argv := []string{"psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "--host=127.0.0.1", "--username=" + t.user, "--dbname=" + t.db, "-f", "-"}
	env := []string{"PGPASSWORD=" + t.password, "PGAPPNAME=launchroom-access", "PGOPTIONS=-c statement_timeout=15000"}
	var out bytes.Buffer
	code, stderr, err := r.RunEnv(ctx, argv, env, []byte(sql), &out)
	if err != nil {
		return err
	}
	if code != 0 {
		return fmt.Errorf("postgres: %s", firstLine(stderr, t.password))
	}
	return nil
}

func runRedis(ctx context.Context, r EnvRunner, engine state.DatabaseEngine, env map[string]secret.Secret, args []string) error {
	argv := append([]string{redisCLI(engine), "--no-auth-warning"}, args...)
	var out bytes.Buffer
	code, stderr, err := r.RunEnv(ctx, argv, redisAuthEnv(env), nil, &out)
	if err != nil {
		return err
	}
	// redis-cli exits 0 on server errors; the reply says it.
	reply := strings.TrimSpace(out.String())
	if code != 0 || strings.HasPrefix(reply, "ERR") || strings.HasPrefix(reply, "(error)") || strings.HasPrefix(reply, "NOAUTH") || strings.HasPrefix(reply, "WRONGPASS") || strings.HasPrefix(reply, "NOPERM") {
		msg := reply
		if msg == "" {
			msg = stderr
		}
		return fmt.Errorf("%s: %s", engine, firstLine(msg, ""))
	}
	return nil
}

// firstLine is a short, masked error line.
func firstLine(s, mask string) string {
	s = strings.TrimSpace(s)
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		s = s[:i]
	}
	if mask != "" {
		s = strings.ReplaceAll(s, mask, "********")
	}
	if len(s) > 300 {
		s = s[:300]
	}
	return s
}
