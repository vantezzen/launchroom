package session

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/csv"
	"encoding/hex"
	"errors"
	"io"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/dbengine"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type postgres struct {
	sqlBase
	p        proc
	user, db string
}

func newPostgres(env envMap, ex execer, container string) (*postgres, error) {
	l, err := dbengine.LoginOf(state.EnginePostgres, env)
	if err != nil {
		return nil, err
	}
	return &postgres{p: newProc(ex, container, "PGPASSWORD="+l.Password, "PGAPPNAME=launchroom"), user: l.User, db: l.Database}, nil
}

func token() string {
	b := make([]byte, 12)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// argv is the psql argv for one statement (docs/03 §11.4).
func (pg *postgres) argv(statement, null, marker string) []string {
	return []string{
		"psql", "-X", "-q", "--csv", "--pset=null=" + null,
		"-v", "ON_ERROR_STOP=1", "-v", "FETCH_COUNT=100", "-v", "SHOW_ALL_RESULTS=off",
		"--host=127.0.0.1", "--username=" + pg.user, "--dbname=" + pg.db,
		"-c", statement, "-c", `\echo ` + marker + ` :ROW_COUNT`,
	}
}

func pgOptions(readOnly bool) string {
	opts := "PGOPTIONS=-c statement_timeout=" + strconv.Itoa(statementTimeoutMs)
	if readOnly {
		opts += " -c default_transaction_read_only=on"
	}
	return opts
}

func (pg *postgres) exec(ctx context.Context, statement string, readOnly bool) *Result {
	null, marker := "__lr_null_"+token()+"__", "__lr_end_"+token()+"__"
	r, err := pg.p.run(ctx, pg.argv(statement, null, marker), MaxRows+200, pgOptions(readOnly))
	if err != nil {
		return failed(err.Error())
	}
	if r.code != 0 && !r.truncated {
		return sqlFailure(r.stderr)
	}
	return parsePsqlCSV(r.out, null, marker, r.truncated)
}

// sqlFailure maps a client error; read-only refusals are "refused".
func sqlFailure(msg string) *Result {
	if msg == "" {
		msg = "The statement failed."
	}
	l := strings.ToLower(msg)
	if strings.Contains(l, "read-only transaction") || strings.Contains(l, "read only transaction") {
		return &Result{Status: state.CommandRefused, Message: msg}
	}
	return failed(msg)
}

// parsePsqlCSV reads psql --csv output up to the end marker line
// ("<marker> <ROW_COUNT>"). No records before the marker means the
// statement returned no rows (affected = ROW_COUNT).
func parsePsqlCSV(out []byte, null, marker string, cut bool) *Result {
	rd := csv.NewReader(bytes.NewReader(out))
	rd.FieldsPerRecord = -1
	res := &Result{Status: state.CommandOK, Truncated: cut}
	var header []string
	sawMarker := false
	for {
		rec, err := rd.Read()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			res.Truncated = true
			break
		}
		if len(rec) == 1 && strings.HasPrefix(rec[0], marker+" ") {
			sawMarker = true
			if header == nil {
				if n, err := strconv.ParseInt(strings.TrimSpace(strings.TrimPrefix(rec[0], marker+" ")), 10, 64); err == nil {
					res.Affected = &n
				}
			}
			break
		}
		if header == nil {
			header = rec
			res.Columns = rec
			res.Rows = [][]*string{}
			continue
		}
		if len(res.Rows) >= MaxRows {
			res.Truncated = true
			break
		}
		row := make([]*string, len(header))
		for i := range header {
			if i < len(rec) && rec[i] != null {
				row[i] = cell(rec[i], &res.CellsTruncated)
			}
		}
		res.Rows = append(res.Rows, row)
	}
	if !sawMarker && !res.Truncated {
		res.Truncated = cut
	}
	if header == nil && res.Affected == nil && !sawMarker {
		res.Message = "The statement ran."
	}
	return res
}

func (pg *postgres) query(ctx context.Context, statement string, allowWrites bool) *Result {
	return pg.exec(ctx, statement, !allowWrites)
}

// pgSchemaSQL lists the table-like objects with the catalog estimate
// (reltuples, -1 or 0 until analyzed), the heap size and whether count(*)
// may run (privilege, populated, no foreign partitions). A partitioned
// table sums its leaves.
const pgSchemaSQL = `SELECT n.nspname, c.relname, c.relkind,
  CASE WHEN c.relkind = 'p' THEN (SELECT sum(GREATEST(l.reltuples, 0)) FROM pg_partition_tree(c.oid) t JOIN pg_class l ON l.oid = t.relid WHERE t.isleaf)::bigint
       WHEN c.relkind IN ('r','m') THEN c.reltuples::bigint END,
  CASE WHEN c.relkind = 'p' THEN COALESCE((SELECT sum(pg_relation_size(t.relid)) FROM pg_partition_tree(c.oid) t WHERE t.isleaf), 0)::bigint
       WHEN c.relkind IN ('r','m') THEN pg_relation_size(c.oid) END,
  c.relkind IN ('r','m','p') AND has_table_privilege(c.oid, 'SELECT') AND (c.relkind <> 'm' OR c.relispopulated)
    AND (c.relkind <> 'p' OR NOT EXISTS (SELECT 1 FROM pg_partition_tree(c.oid) t JOIN pg_class l ON l.oid = t.relid WHERE l.relkind = 'f'))
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r','v','m','p','f') AND n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%'
ORDER BY 1, 2 LIMIT 2000`

var pgKinds = map[string]string{"r": "table", "v": "view", "m": "materialized_view", "p": "partitioned_table", "f": "foreign_table"}

func (pg *postgres) schema(ctx context.Context) *Result {
	r := pg.exec(ctx, pgSchemaSQL, true)
	if r.Status != state.CommandOK {
		return r
	}
	info, targets := pgSchemaInfo(r)
	if picked := pickCounts(targets); len(picked) > 0 {
		applyCounts(info, picked, pg.exec(ctx, "SET statement_timeout = "+strconv.Itoa(countTimeoutMs)+"; "+countSQL(picked, `"`), true))
	}
	return &Result{Status: state.CommandOK, Truncated: r.Truncated, Schema: info}
}

// pgSchemaInfo groups pgSchemaSQL rows by schema and returns the tables count(*) may run on.
func pgSchemaInfo(r *Result) (*SchemaInfo, []countTarget) {
	info := &SchemaInfo{Engine: state.EnginePostgres, Groups: []SchemaGroup{}}
	var targets []countTarget
	for _, row := range r.Rows {
		if len(row) != 6 || row[0] == nil || row[1] == nil || row[2] == nil {
			continue
		}
		if len(info.Groups) == 0 || info.Groups[len(info.Groups)-1].Name != *row[0] {
			info.Groups = append(info.Groups, SchemaGroup{Name: *row[0], Items: []SchemaItem{}})
		}
		item := SchemaItem{Name: *row[1], Kind: pgKinds[*row[2]], Rows: estimate(row[3])}
		item.Estimate = item.Rows != nil
		g := &info.Groups[len(info.Groups)-1]
		if row[4] != nil && row[5] != nil && *row[5] == "t" && countable(*row[0], *row[1]) {
			if size, err := strconv.ParseInt(*row[4], 10, 64); err == nil {
				targets = append(targets, countTarget{group: len(info.Groups) - 1, item: len(g.Items),
					schema: state.TableName(*row[0]), table: state.TableName(*row[1]), size: size})
			}
		}
		g.Items = append(g.Items, item)
	}
	return info, targets
}

// quoteIdent quotes a grammar-checked identifier for SQL ("" doubling).
func quoteIdent(s state.TableName, q string) string {
	return q + strings.ReplaceAll(string(s), q, q+q) + q
}

func (pg *postgres) rows(ctx context.Context, t state.TableRef, page, size int) *Result {
	if err := t.Name.Validate(); err != nil {
		return refused("That is not a valid table name.")
	}
	sch := t.Schema
	if sch == "" {
		sch = "public"
	} else if err := sch.Validate(); err != nil {
		return refused("That is not a valid schema name.")
	}
	sql := "SELECT * FROM " + quoteIdent(sch, `"`) + "." + quoteIdent(t.Name, `"`) +
		" LIMIT " + strconv.Itoa(size) + " OFFSET " + strconv.Itoa(page*size)
	return pg.exec(ctx, sql, true)
}
