package session

import (
	"bytes"
	"context"
	"encoding/xml"
	"errors"
	"io"
	"strconv"

	"github.com/vantezzen/launchroom/daemon/internal/dbengine"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type mysql struct {
	sqlBase
	p        proc
	bin      string
	mariadb  bool
	user, db string
}

func newMySQL(env envMap, ex execer, container string, engine state.DatabaseEngine) (*mysql, error) {
	l, err := dbengine.LoginOf(engine, env)
	if err != nil {
		return nil, err
	}
	return &mysql{p: newProc(ex, container, "MYSQL_PWD="+l.Password), bin: dbengine.Client(engine), mariadb: engine == state.EngineMariaDB, user: l.User, db: l.Database}, nil
}

// initCommand sets the statement timeout and (read-only mode) a read-only session.
func (m *mysql) initCommand(readOnly bool) string {
	s := "SET SESSION max_execution_time=" + strconv.Itoa(statementTimeoutMs)
	if m.mariadb {
		s = "SET SESSION max_statement_time=" + strconv.Itoa(statementTimeoutMs/1000)
	}
	if readOnly {
		s += ", SESSION transaction_read_only=ON"
	}
	return s
}

func (m *mysql) argv(statement string, readOnly bool) []string {
	return []string{
		m.bin, "--protocol=tcp", "--host=127.0.0.1", "--user=" + m.user, "--database=" + m.db,
		"--xml", "--quick", "--init-command=" + m.initCommand(readOnly), "-e", statement,
	}
}

func (m *mysql) exec(ctx context.Context, statement string, readOnly bool) *Result {
	r, err := m.p.run(ctx, m.argv(statement, readOnly), 0)
	if err != nil {
		return failed(err.Error())
	}
	if r.code != 0 && !r.truncated {
		return sqlFailure(r.stderr)
	}
	return parseMySQLXML(r.out, r.truncated)
}

// parseMySQLXML reads `mysql --xml` output and keeps the last result set.
func parseMySQLXML(out []byte, cut bool) *Result {
	dec := xml.NewDecoder(bytes.NewReader(out))
	res := &Result{Status: state.CommandOK, Truncated: cut}
	var (
		sets    int
		cols    []string
		rows    [][]*string
		row     []*string
		inField bool
		isNull  bool
		text    []byte
		names   []string
	)
	for {
		tok, err := dec.Token()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			res.Truncated = true
			break
		}
		switch t := tok.(type) {
		case xml.StartElement:
			switch t.Name.Local {
			case "resultset":
				sets++
				cols, rows, names = nil, [][]*string{}, nil
			case "row":
				row, names = nil, nil
			case "field":
				inField, isNull, text = true, false, nil
				for _, a := range t.Attr {
					if a.Name.Local == "name" {
						names = append(names, a.Value)
					}
					if a.Name.Local == "nil" && a.Value == "true" {
						isNull = true
					}
				}
			}
		case xml.CharData:
			if inField {
				text = append(text, t...)
			}
		case xml.EndElement:
			switch t.Name.Local {
			case "field":
				inField = false
				if isNull {
					row = append(row, nil)
				} else {
					row = append(row, cell(string(text), &res.CellsTruncated))
				}
			case "row":
				if cols == nil {
					cols = names
				}
				if len(rows) >= MaxRows {
					res.Truncated = true
					continue
				}
				rows = append(rows, row)
			}
		}
	}
	if sets == 0 {
		res.Message = "The statement ran."
		return res
	}
	if cols == nil {
		cols = []string{}
	}
	res.Columns, res.Rows = cols, rows
	return res
}

func (m *mysql) query(ctx context.Context, statement string, allowWrites bool) *Result {
	return m.exec(ctx, statement, !allowWrites)
}

// mysqlSchemaSQL lists tables with InnoDB's row estimate and data size.
// MySQL caches both for a day (information_schema_stats_expiry), so its
// query reads them fresh first; MariaDB has no such cache.
const mysqlSchemaSQL = "SELECT table_name AS name, table_type AS kind, table_rows AS est, data_length AS size FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name LIMIT 2000"

func (m *mysql) schema(ctx context.Context) *Result {
	var r *Result
	if !m.mariadb {
		r = m.exec(ctx, "SET SESSION information_schema_stats_expiry = 0; "+mysqlSchemaSQL, true)
	}
	if r == nil || r.Status != state.CommandOK {
		r = m.exec(ctx, mysqlSchemaSQL, true)
	}
	if r.Status != state.CommandOK {
		return r
	}
	engine := state.EngineMySQL
	if m.mariadb {
		engine = state.EngineMariaDB
	}
	info, targets := mysqlSchemaInfo(r, m.db, engine)
	if picked := pickCounts(targets); len(picked) > 0 {
		limit := "SET SESSION max_execution_time = " + strconv.Itoa(countTimeoutMs) + "; "
		if m.mariadb {
			limit = "SET SESSION max_statement_time = " + strconv.Itoa(countTimeoutMs/1000) + "; "
		}
		applyCounts(info, picked, m.exec(ctx, limit+countSQL(picked, "`"), true))
	}
	return &Result{Status: state.CommandOK, Truncated: r.Truncated, Schema: info}
}

// mysqlSchemaInfo builds the one-database group and the base tables count(*) may run on.
func mysqlSchemaInfo(r *Result, db string, engine state.DatabaseEngine) (*SchemaInfo, []countTarget) {
	g := SchemaGroup{Name: db, Items: []SchemaItem{}}
	var targets []countTarget
	for _, row := range r.Rows {
		if len(row) != 4 || row[0] == nil {
			continue
		}
		kind := "table"
		if row[1] != nil && *row[1] == "VIEW" {
			kind = "view"
		}
		item := SchemaItem{Name: *row[0], Kind: kind}
		if kind == "table" {
			item.Rows = estimate(row[2])
			item.Estimate = item.Rows != nil
			if row[3] != nil && countable("", *row[0]) {
				if size, err := strconv.ParseInt(*row[3], 10, 64); err == nil {
					targets = append(targets, countTarget{item: len(g.Items), table: state.TableName(*row[0]), size: size})
				}
			}
		}
		g.Items = append(g.Items, item)
	}
	return &SchemaInfo{Engine: engine, Groups: []SchemaGroup{g}}, targets
}

func (m *mysql) rows(ctx context.Context, t state.TableRef, page, size int) *Result {
	if err := t.Name.Validate(); err != nil {
		return refused("That is not a valid table name.")
	}
	sql := "SELECT * FROM " + quoteIdent(t.Name, "`") + " LIMIT " + strconv.Itoa(size) + " OFFSET " + strconv.Itoa(page*size)
	return m.exec(ctx, sql, true)
}
