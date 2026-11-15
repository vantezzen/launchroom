package dbengine

import (
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestIdentAndPassword(t *testing.T) {
	env := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "x; drop", "POSTGRES_PASSWORD": "pw"}
	if v, err := Ident(env, "postgres", "POSTGRES_USER"); err != nil || v != "app" {
		t.Fatalf("%q %v", v, err)
	}
	if v, err := Ident(env, "postgres", "PGUSER"); err != nil || v != "postgres" {
		t.Fatalf("default: %q %v", v, err)
	}
	if _, err := Ident(env, "app", "POSTGRES_DB"); err == nil {
		t.Fatal("an identifier outside the grammar was accepted")
	}
	if v, err := Password(env, "MYSQL_PASSWORD", "POSTGRES_PASSWORD"); err != nil || v != "pw" {
		t.Fatalf("%q %v", v, err)
	}
	if _, err := Password(env, "REDIS_PASSWORD"); err == nil {
		t.Fatal("missing password")
	}
}

func TestLoginOf(t *testing.T) {
	cases := []struct {
		engine state.DatabaseEngine
		env    map[string]secret.Secret
		want   Login
	}{
		{state.EnginePostgres, map[string]secret.Secret{"POSTGRES_PASSWORD": "pw"}, Login{User: "postgres", Database: "postgres", Password: "pw"}},
		{state.EnginePostgres, map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_PASSWORD": "pw"}, Login{User: "app", Database: "app", Password: "pw"}},
		{state.EngineMariaDB, map[string]secret.Secret{"MARIADB_USER": "u", "MARIADB_DATABASE": "d", "MARIADB_PASSWORD": "pw", "MARIADB_ROOT_PASSWORD": "root"}, Login{User: "u", Database: "d", Password: "pw", Root: "root"}},
		{state.EngineMongoDB, map[string]secret.Secret{"MONGO_INITDB_ROOT_USERNAME": "admin", "MONGO_INITDB_ROOT_PASSWORD": "pw"}, Login{User: "admin", Database: "test", Password: "pw"}},
		{state.EngineClickHouse, map[string]secret.Secret{}, Login{User: "default", Database: "default"}},
		{state.EngineValkey, map[string]secret.Secret{RedisPassword: "pw"}, Login{Password: "pw"}},
	}
	for _, c := range cases {
		got, err := LoginOf(c.engine, c.env)
		if err != nil || got != c.want {
			t.Errorf("%s: %+v %v, want %+v", c.engine, got, err, c.want)
		}
	}
	for engine, env := range map[state.DatabaseEngine]map[string]secret.Secret{
		state.EnginePostgres: {"POSTGRES_USER": "app"},                                                  // no password
		state.EngineMySQL:    {"MYSQL_DATABASE": "d", "MYSQL_PASSWORD": "pw"},                           // no user
		state.EngineRedis:    {},                                                                        // no password
		state.EngineMongoDB:  {"MONGO_INITDB_ROOT_USERNAME": "a b", "MONGO_INITDB_ROOT_PASSWORD": "pw"}, // not an identifier
	} {
		if _, err := LoginOf(engine, env); err == nil {
			t.Errorf("%s: %v accepted", engine, env)
		}
	}
}

// Temporary database access connects to the engine's port; an engine
// added to state.AccessEngines needs one here.
func TestAccessEnginesHavePorts(t *testing.T) {
	for _, e := range state.AccessEngines {
		if Of(e).Port == 0 {
			t.Errorf("%s has no port", e)
		}
	}
}
