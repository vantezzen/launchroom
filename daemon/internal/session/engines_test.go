package session

import (
	"strings"
	"testing"
)

func TestCheckMongoCommand(t *testing.T) {
	for _, c := range []struct {
		stmt   string
		writes bool
		ok     bool
	}{
		{`{"find": "users", "filter": {"plan": "pro"}}`, false, true},
		{`{"aggregate": "users", "pipeline": [{"$match": {}}], "cursor": {}}`, false, true},
		{`{"aggregate": "users", "pipeline": [{"$out": "copy"}], "cursor": {}}`, false, false},
		{`{"aggregate": "users", "pipeline": [{"$facet": {"a": [{"$merge": "x"}]}}], "cursor": {}}`, false, false},
		{`{"aggregate": "users", "pipeline": [{"$out": "copy"}], "cursor": {}}`, true, true},
		{`{"insert": "users", "documents": [{"a": 1}]}`, false, false},
		{`{"insert": "users", "documents": [{"a": 1}]}`, true, true},
		{`{"Find": "users"}`, false, true},
		{`{"shutdown": 1}`, true, false},
		{`{"createUser": "x", "pwd": "y", "roles": []}`, true, false},
		{`{"find": "users", "$db": "admin"}`, false, false},
		{`{"find": "users"} {"drop": "users"}`, true, false},
		{`db.users.find()`, false, false},
		{`[{"find": "users"}]`, false, false},
		{`{"find": "users", "find": "other"}`, false, false},
	} {
		err := checkMongoCommand(c.stmt, c.writes)
		if (err == nil) != c.ok {
			t.Errorf("%s (writes %v): err = %v, want ok %v", c.stmt, c.writes, err, c.ok)
		}
	}
}

func TestMongoDocuments(t *testing.T) {
	s := func(v string) *string { return &v }
	res := documents([][][]*string{
		{{s("name"), s("Ada")}, {s("_id"), s(`{"$oid":"1"}`)}},
		{{s("_id"), s(`{"$oid":"2"}`)}, {s("age"), s("36")}, {s("name"), nil}},
	}, true)
	if strings.Join(res.Columns, ",") != "_id,name,age" || !res.Truncated || len(res.Rows) != 2 {
		t.Fatalf("columns %v truncated %v rows %d", res.Columns, res.Truncated, len(res.Rows))
	}
	if *res.Rows[0][1] != "Ada" || res.Rows[0][2] != nil || res.Rows[1][1] != nil || *res.Rows[1][2] != "36" {
		t.Fatalf("rows %v", res.Rows)
	}
}

func TestParseClickHouseRows(t *testing.T) {
	res := parseClickHouseRows([]byte("[\"id\",\"name\"]\n[\"1\",\"a\"]\n[\"2\",null]\n"), false)
	if strings.Join(res.Columns, ",") != "id,name" || len(res.Rows) != 2 || *res.Rows[0][1] != "a" || res.Rows[1][1] != nil {
		t.Fatalf("%+v", res)
	}
	if r := parseClickHouseRows(nil, false); r.Message == "" || r.Columns != nil {
		t.Fatalf("empty output: %+v", r)
	}
	if r := clickhouseFailure("Code: 164. DB::Exception: Received from 127.0.0.1:9000. DB::Exception: app: Cannot execute query in readonly mode. (READONLY)"); r.Status != "refused" || r.Message != "app: Cannot execute query in readonly mode. (READONLY)" {
		t.Fatalf("readonly refusal: %+v", r)
	}
}

func TestClickHouseArgvReadOnlyLast(t *testing.T) {
	c := &clickhouse{db: "app"}
	argv := c.argv("SELECT 1", true)
	if argv[len(argv)-3] != "--readonly=1" || argv[len(argv)-1] != "SELECT 1" {
		t.Fatalf("argv %v", argv)
	}
	for _, a := range c.argv("SELECT 1", false) {
		if strings.HasPrefix(a, "--readonly") || strings.Contains(a, "password") {
			t.Fatalf("argv %v", c.argv("SELECT 1", false))
		}
	}
}
