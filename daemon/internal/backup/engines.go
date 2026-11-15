package backup

import (
	"bytes"
	"fmt"
	"io"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/dbengine"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

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
	return secret.NewMasker(vals...).Mask(firstLines(stderr))
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

// dumpFacts are what an engine's dumps look like (exports, imports).
type dumpFacts struct {
	// family is the server family a dump names ("PostgreSQL"; "" for engines without imports).
	family string
	// format is what a live dump of this engine is (imports from a URL;
	// only engines with imports).
	format state.DataFormat
	// export is the native format of an export, gzipped when exportGzip.
	export     state.DataFormat
	exportGzip bool
}

var dumps = map[state.DatabaseEngine]dumpFacts{
	state.EnginePostgres:   {family: familyPostgres, format: state.FormatPostgresSQL, export: state.FormatPostgresCustom},
	state.EngineMySQL:      {family: familyMySQL, format: state.FormatMySQLSQL, export: state.FormatMySQLSQL, exportGzip: true},
	state.EngineMariaDB:    {family: familyMariaDB, format: state.FormatMySQLSQL, export: state.FormatMySQLSQL, exportGzip: true},
	state.EngineMongoDB:    {family: familyMongoDB, format: state.FormatMongoArchive, export: state.FormatMongoArchive},
	state.EngineClickHouse: {export: state.FormatClickHouseBackup},
	state.EngineRedis:      {export: state.FormatRedisRDB},
	state.EngineValkey:     {export: state.FormatRedisRDB},
}

func dumpsOf(e state.DatabaseEngine) dumpFacts { return dumps[e] }

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
		return mysqlEngine(e, "mysql.sql"), nil
	case state.EngineMariaDB:
		return mysqlEngine(e, "mariadb.sql"), nil
	case state.EngineRedis:
		return redisEngine(e, "redis.rdb"), nil
	case state.EngineValkey:
		return redisEngine(e, "valkey.rdb"), nil
	case state.EngineMongoDB:
		return &mongo, nil
	case state.EngineClickHouse:
		return &clickhouse, nil
	default:
		return nil, fmt.Errorf("backup: engine %q: %w", e, ErrUnsupported)
	}
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

// conn is the service's own login (dbengine.LoginOf).
func conn(e state.DatabaseEngine, env map[string]secret.Secret) (user, db, pw string, err error) {
	l, err := dbengine.LoginOf(e, env)
	return l.User, l.Database, l.Password, err
}

func pgConn(env map[string]secret.Secret) (user, db, pw string, err error) {
	return conn(state.EnginePostgres, env)
}

func mysqlEngine(e state.DatabaseEngine, file string) *engine {
	dumpBin, clientBin := dbengine.Of(e).Dump, dbengine.Client(e)
	return &engine{
		file: file,
		dump: func(env map[string]secret.Secret) (command, error) {
			user, db, pw, err := conn(e, env)
			if err != nil {
				return command{}, err
			}
			return command{
				argv: []string{dumpBin, "--single-transaction", "--routines", "--triggers", "--no-tablespaces", "--protocol=tcp", "--host=127.0.0.1", "--user=" + user, db},
				env:  []string{"MYSQL_PWD=" + pw},
			}, nil
		},
		restore: func(env map[string]secret.Secret) (command, error) {
			user, db, pw, err := conn(e, env)
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

func redisEngine(e state.DatabaseEngine, file string) *engine {
	cli := dbengine.Client(e)
	return &engine{
		file: file,
		dump: func(env map[string]secret.Secret) (command, error) {
			_, _, pw, err := conn(e, env)
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
	return []string{tool, "--host=127.0.0.1", "--port=" + dbengine.Of(state.EngineMongoDB).PortText(), "--username=" + user, "--authenticationDatabase=admin", "--quiet"}
}

func mongoConn(env map[string]secret.Secret) (user, db, pw string, err error) {
	if user, db, pw, err = conn(state.EngineMongoDB, env); err != nil {
		return "", "", "", err
	}
	if strings.ContainsAny(pw, "\r\n") {
		// The password is the first line of stdin (mongodump/mongorestore).
		return "", "", "", fmt.Errorf("backup: MONGO_INITDB_ROOT_PASSWORD contains a line break")
	}
	return user, db, pw, nil
}

// ClickHouse (D47) is backed up with its native BACKUP into the data
// volume's backups/ directory (the image's default backups.allowed_path),
// which launchroomd then streams out; see clickhouse.go. dump/restore are
// unused for it.
var clickhouse = engine{file: ClickHouseFile}
