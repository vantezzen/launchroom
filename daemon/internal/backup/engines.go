package backup

import (
	"bytes"
	"fmt"
	"io"
	"regexp"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// reDBIdent is the grammar for database and user names taken from a
// workload's env before they are put into argv.
var reDBIdent = regexp.MustCompile(`^[A-Za-z0-9_]{1,63}$`)

// command is one process run inside the database container: argv plus exec
// env (credentials only ever travel in env, docs/14 §7).
type command struct {
	argv []string
	env  []string
	// stdin is written to the process' stdin before anything else (MongoDB's
	// tools read the password there when it is not on the command line).
	stdin []byte
}

// input returns the process' stdin: the command's own prefix, then rest.
func (c command) input(rest io.Reader) io.Reader {
	switch {
	case c.stdin == nil:
		return rest
	case rest == nil:
		return bytes.NewReader(c.stdin)
	default:
		return io.MultiReader(bytes.NewReader(c.stdin), rest)
	}
}

// masked returns a short excerpt of the tool's stderr with the exec env's
// secret values masked.
func (c command) masked(stderr string) string {
	vals := make([]string, 0, len(c.env))
	for _, kv := range c.env {
		if _, v, ok := strings.Cut(kv, "="); ok {
			vals = append(vals, v)
		}
	}
	if c.stdin != nil {
		vals = append(vals, strings.TrimSpace(string(c.stdin)))
	}
	return build.NewMasker(vals...).Mask(firstLines(stderr))
}

// engine knows how to dump and restore one database engine. Argv is built
// here from fixed templates and grammar-checked values of the workload's own
// env; nothing comes from the task spec.
type engine struct {
	// file is the restic --stdin-filename; restores require a snapshot of exactly this path.
	file    string
	dump    func(env map[string]secret.Secret) (command, error)
	restore func(env map[string]secret.Secret) (command, error)
	// base streams a physical base backup (tar) to stdout; Postgres only (docs/14 §10).
	base func(env map[string]secret.Secret) (command, error)
}

// BaseBackupFile is the restic --stdin-filename of Postgres base backups: the
// tar of PGDATA written by pg_basebackup, including the WAL needed to make
// it consistent (--wal-method=fetch), so it restores without the archive.
const BaseBackupFile = "postgres-base.tar"

// SnapshotFile returns the file name inside snapshots of an engine ("postgres.dump").
func SnapshotFile(e state.DatabaseEngine) (string, error) {
	en, err := engineFor(e)
	if err != nil {
		return "", err
	}
	return en.file, nil
}

func engineFor(e state.DatabaseEngine) (*engine, error) {
	switch e {
	case state.EnginePostgres:
		return &postgres, nil
	case state.EngineMySQL:
		return mysqlEngine("mysql.sql", "mysqldump", "mysql"), nil
	case state.EngineMariaDB:
		return mysqlEngine("mariadb.sql", "mariadb-dump", "mariadb"), nil
	case state.EngineRedis:
		return redisEngine("redis.rdb", "redis-cli"), nil
	case state.EngineValkey:
		return redisEngine("valkey.rdb", "valkey-cli"), nil
	case state.EngineMongoDB:
		return &mongo, nil
	case state.EngineClickHouse:
		return &clickhouse, nil
	default:
		return nil, fmt.Errorf("backup: engine %q: %w", e, ErrUnsupported)
	}
}

// ident returns a grammar-checked identifier from env (or def).
func ident(env map[string]secret.Secret, def string, names ...string) (string, error) {
	v := def
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			v = s.Reveal()
			break
		}
	}
	if !reDBIdent.MatchString(v) {
		return "", fmt.Errorf("backup: %s in the workload env is not a valid database identifier", names[0])
	}
	return v, nil
}

func password(env map[string]secret.Secret, names ...string) (string, error) {
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			return s.Reveal(), nil
		}
	}
	return "", fmt.Errorf("backup: the workload env has no %s", names[0])
}

var postgres = engine{
	file: "postgres.dump",
	dump: func(env map[string]secret.Secret) (command, error) {
		user, db, pw, err := pgConn(env)
		if err != nil {
			return command{}, err
		}
		return command{
			argv: []string{"pg_dump", "--format=custom", "--no-owner", "--no-privileges", "--host=127.0.0.1", "--username=" + user, "--dbname=" + db},
			env:  []string{"PGPASSWORD=" + pw},
		}, nil
	},
	restore: func(env map[string]secret.Secret) (command, error) {
		user, db, pw, err := pgConn(env)
		if err != nil {
			return command{}, err
		}
		return command{
			argv: []string{
				"pg_restore", "--clean", "--if-exists", "--no-owner", "--no-privileges", "--single-transaction", "--exit-on-error",
				"--host=127.0.0.1", "--username=" + user, "--dbname=" + db,
			},
			env: []string{"PGPASSWORD=" + pw},
		}, nil
	},
	base: func(env map[string]secret.Secret) (command, error) {
		user, _, pw, err := pgConn(env)
		if err != nil {
			return command{}, err
		}
		// One tar on stdout: only possible without WAL streaming (fetch
		// collects the WAL at the end) and without extra tablespaces.
		return command{
			argv: []string{
				"pg_basebackup", "--pgdata=-", "--format=tar", "--wal-method=fetch", "--checkpoint=fast", "--no-manifest",
				"--label=launchroom", "--host=127.0.0.1", "--username=" + user,
			},
			env: []string{"PGPASSWORD=" + pw},
		}, nil
	},
}

func pgConn(env map[string]secret.Secret) (user, db, pw string, err error) {
	if user, err = ident(env, "postgres", "POSTGRES_USER"); err != nil {
		return "", "", "", err
	}
	if db, err = ident(env, user, "POSTGRES_DB"); err != nil {
		return "", "", "", err
	}
	pw, err = password(env, "POSTGRES_PASSWORD")
	return user, db, pw, err
}

func mysqlEngine(file, dumpBin, clientBin string) *engine {
	conn := func(env map[string]secret.Secret) (user, db, pw string, err error) {
		if user, err = ident(env, "", "MYSQL_USER", "MARIADB_USER"); err != nil {
			return "", "", "", err
		}
		if db, err = ident(env, "", "MYSQL_DATABASE", "MARIADB_DATABASE"); err != nil {
			return "", "", "", err
		}
		pw, err = password(env, "MYSQL_PASSWORD", "MARIADB_PASSWORD")
		return user, db, pw, err
	}
	return &engine{
		file: file,
		dump: func(env map[string]secret.Secret) (command, error) {
			user, db, pw, err := conn(env)
			if err != nil {
				return command{}, err
			}
			return command{
				argv: []string{dumpBin, "--single-transaction", "--routines", "--triggers", "--no-tablespaces", "--protocol=tcp", "--host=127.0.0.1", "--user=" + user, db},
				env:  []string{"MYSQL_PWD=" + pw},
			}, nil
		},
		restore: func(env map[string]secret.Secret) (command, error) {
			user, db, pw, err := conn(env)
			if err != nil {
				return command{}, err
			}
			return command{
				argv: []string{clientBin, "--protocol=tcp", "--host=127.0.0.1", "--user=" + user, "--database=" + db},
				env:  []string{"MYSQL_PWD=" + pw},
			}, nil
		},
	}
}

func redisEngine(file, cli string) *engine {
	return &engine{
		file: file,
		dump: func(env map[string]secret.Secret) (command, error) {
			pw, err := password(env, "REDIS_PASSWORD")
			if err != nil {
				return command{}, err
			}
			return command{argv: []string{cli, "--rdb", "-"}, env: []string{"REDISCLI_AUTH=" + pw}}, nil
		},
		restore: func(map[string]secret.Secret) (command, error) {
			// Loading an RDB needs the server stopped with its AOF replaced;
			// that is not possible through exec without a shell (not supported yet).
			return command{}, fmt.Errorf("backup: restoring %s snapshots: %w", file, ErrUnsupported)
		},
	}
}

// MongoDB (D47): mongodump/mongorestore of the workload's one database as a
// gzipped archive on stdout/stdin. The tools have no password env variable
// and a password in argv shows in ps, so it is the first line of stdin: they
// read it there when --username is given without --password (stdin is not a
// terminal), byte by byte, so the archive that follows is untouched.
var mongo = engine{
	file: "mongodb.archive",
	dump: func(env map[string]secret.Secret) (command, error) {
		user, db, pw, err := mongoConn(env)
		if err != nil {
			return command{}, err
		}
		return command{
			argv:  append(mongoAuthArgs("mongodump", user), "--archive", "--gzip", "--db="+db),
			stdin: []byte(pw + "\n"),
		}, nil
	},
	restore: func(env map[string]secret.Secret) (command, error) {
		user, db, pw, err := mongoConn(env)
		if err != nil {
			return command{}, err
		}
		// Every namespace of the archive lands in the target's database
		// (a copy may be named differently); --drop replaces collections.
		return command{
			argv: append(mongoAuthArgs("mongorestore", user), "--archive", "--gzip", "--drop",
				"--nsFrom=$db$.$coll$", "--nsTo="+db+".$coll$", "--stopOnError"),
			stdin: []byte(pw + "\n"),
		}, nil
	},
}

func mongoAuthArgs(tool, user string) []string {
	return []string{tool, "--host=127.0.0.1", "--port=27017", "--username=" + user, "--authenticationDatabase=admin", "--quiet"}
}

func mongoConn(env map[string]secret.Secret) (user, db, pw string, err error) {
	if user, err = ident(env, "", "MONGO_INITDB_ROOT_USERNAME"); err != nil {
		return "", "", "", err
	}
	if db, err = ident(env, "test", "MONGO_INITDB_DATABASE"); err != nil {
		return "", "", "", err
	}
	pw, err = password(env, "MONGO_INITDB_ROOT_PASSWORD")
	if err != nil {
		return "", "", "", err
	}
	if strings.ContainsAny(pw, "\r\n") {
		return "", "", "", fmt.Errorf("backup: MONGO_INITDB_ROOT_PASSWORD contains a line break")
	}
	return user, db, pw, nil
}

// ClickHouse (D47) is backed up with its native BACKUP into the data
// volume's backups/ directory (the image's default backups.allowed_path),
// which launchroomd then streams out; see clickhouse.go. dump/restore are
// unused for it.
var clickhouse = engine{file: ClickHouseFile}
