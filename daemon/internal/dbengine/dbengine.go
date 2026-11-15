// Package dbengine holds what launchroomd knows about each database engine
// that backups, imports, data sessions and remote access share: the tools
// in the engine's own image, its port and connection URL schemes, and
// which workload env names hold the service's own login, read and checked
// before anything goes into argv or an exec env (docs/14 §7). What each
// use does with an engine (the commands it runs) stays with that use.
package dbengine

import (
	"fmt"
	"strconv"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// RedisPassword is the env name of a Redis or Valkey service's password
// (the dashboard's database engine driver sets it for both).
const RedisPassword = "REDIS_PASSWORD"

// Facts are one engine's facts.
type Facts struct {
	// Client is the command-line client in the engine's image ("" for
	// engines whose tools differ per use).
	Client string
	// Dump is the logical dump tool in the image ("" when there is none).
	Dump string
	// Port is the engine's TCP port inside its container (0: unknown).
	Port int
	// URLSchemes are the connection URL schemes of an import source
	// (nil: no URL imports).
	URLSchemes []string
	login      login
}

// login names the workload env that holds the service's own login, as the
// dashboard's database engine drivers set it. The first name set wins; an
// unset user or database falls back to its default (dbFromUser: the user).
type login struct {
	user, db, password, root []string
	defaultUser, defaultDB   string
	dbFromUser               bool
	passwordOptional         bool
}

var facts = map[state.DatabaseEngine]Facts{
	state.EnginePostgres: {Client: "psql", Dump: "pg_dump", Port: 5432, URLSchemes: []string{"postgres", "postgresql"}, login: login{
		user: []string{"POSTGRES_USER"}, defaultUser: "postgres", db: []string{"POSTGRES_DB"}, dbFromUser: true, password: []string{"POSTGRES_PASSWORD"},
	}},
	state.EngineMySQL:   {Client: "mysql", Dump: "mysqldump", Port: 3306, URLSchemes: []string{"mysql", "mariadb"}, login: mysqlLogin},
	state.EngineMariaDB: {Client: "mariadb", Dump: "mariadb-dump", Port: 3306, URLSchemes: []string{"mysql", "mariadb"}, login: mysqlLogin},
	state.EngineRedis:   {Client: "redis-cli", Port: 6379, login: login{password: []string{RedisPassword}}},
	state.EngineValkey:  {Client: "valkey-cli", Port: 6379, login: login{password: []string{RedisPassword}}},
	state.EngineMongoDB: {Dump: "mongodump", Port: 27017, URLSchemes: []string{"mongodb", "mongodb+srv"}, login: login{
		user: []string{"MONGO_INITDB_ROOT_USERNAME"}, db: []string{"MONGO_INITDB_DATABASE"}, defaultDB: "test", password: []string{"MONGO_INITDB_ROOT_PASSWORD"},
	}},
	state.EngineClickHouse: {login: login{
		user: []string{"CLICKHOUSE_USER"}, defaultUser: "default", db: []string{"CLICKHOUSE_DATABASE", "CLICKHOUSE_DB"}, defaultDB: "default",
		password: []string{"CLICKHOUSE_PASSWORD"}, passwordOptional: true,
	}},
}

var mysqlLogin = login{
	user: []string{"MYSQL_USER", "MARIADB_USER"}, db: []string{"MYSQL_DATABASE", "MARIADB_DATABASE"},
	password: []string{"MYSQL_PASSWORD", "MARIADB_PASSWORD"}, root: []string{"MYSQL_ROOT_PASSWORD", "MARIADB_ROOT_PASSWORD"},
}

// Of returns an engine's facts (zero for an unknown engine).
func Of(e state.DatabaseEngine) Facts { return facts[e] }

// Client is the engine's command-line client in its own image.
func Client(e state.DatabaseEngine) string { return facts[e].Client }

// PortText is the engine's port as text ("5432"; "" when it has none).
func (f Facts) PortText() string {
	if f.Port == 0 {
		return ""
	}
	return strconv.Itoa(f.Port)
}

// Login is a service's own login from its workload env. User and Database
// match the identifier grammar (state.DatabaseIdent); Password may be
// empty only where the engine allows no password; Root is the MySQL root
// password when set. Engines without users (Redis, Valkey) have only a
// password.
type Login struct {
	User, Database, Password, Root string
}

// LoginOf reads e's login from env.
func LoginOf(e state.DatabaseEngine, env map[string]secret.Secret) (Login, error) {
	f, ok := facts[e]
	if !ok {
		return Login{}, fmt.Errorf("database engine %q is not known", e)
	}
	l := f.login
	var out Login
	var err error
	if len(l.user) > 0 {
		if out.User, err = Ident(env, l.defaultUser, l.user...); err != nil {
			return Login{}, err
		}
		def := l.defaultDB
		if l.dbFromUser {
			def = out.User
		}
		if out.Database, err = Ident(env, def, l.db...); err != nil {
			return Login{}, err
		}
	}
	if out.Password, err = Password(env, l.password...); err != nil && !l.passwordOptional {
		return Login{}, err
	}
	if len(l.root) > 0 {
		out.Root, _ = Password(env, l.root...)
	}
	return out, nil
}

// Ident returns the first of names set in env (or def), checked against
// the identifier grammar (state.DatabaseIdent) before it may go into argv.
func Ident(env map[string]secret.Secret, def string, names ...string) (string, error) {
	v := def
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			v = s.Reveal()
			break
		}
	}
	if state.DatabaseIdent(v).Validate() != nil {
		return "", fmt.Errorf("%s in the workload env is not a valid database identifier", names[0])
	}
	return v, nil
}

// Password returns the first of names set in env.
func Password(env map[string]secret.Secret, names ...string) (string, error) {
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			return s.Reveal(), nil
		}
	}
	return "", fmt.Errorf("the workload env has no %s", names[0])
}
