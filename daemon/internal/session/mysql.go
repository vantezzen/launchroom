package session

import (
	"bytes"
	"context"
	"encoding/xml"
	"errors"
	"io"
	"strconv"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type mysql struct {
	sqlBase
	p        proc
	bin      string
	mariadb  bool
	user, db string
}

func newMySQL(env envMap, ex execer, container, bin string, mariadb bool) (*mysql, error) {
	user, err := ident(env, "", "MYSQL_USER", "MARIADB_USER")
	if err != nil {
		return nil, err
	}
	db, err := ident(env, "", "MYSQL_DATABASE", "MARIADB_DATABASE")
	if err != nil {
		return nil, err
	}
	pw, err := password(env, "MYSQL_PASSWORD", "MARIADB_PASSWORD")
	if err != nil {
		return nil, err
	}
	return &mysql{p: newProc(ex, container, "MYSQL_PWD="+pw), bin: bin, mariadb: mariadb, user: user, db: db}, nil
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

func (m *mysql) schema(ctx context.Context) *Result {
	r := m.exec(ctx, "SELECT table_name AS name, table_type AS kind, table_rows AS est FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name LIMIT 2000", true)
	if r.Status != state.CommandOK {
		return r
	}
	engine := state.EngineMySQL
	if m.mariadb {
		engine = state.EngineMariaDB
	}
	g := SchemaGroup{Name: m.db, Items: []SchemaItem{}}
	for _, row := range r.Rows {
		if len(row) != 3 || row[0] == nil {
			continue
		}
		kind := "table"
		if row[1] != nil && *row[1] == "VIEW" {
			kind = "view"
		}
		item := SchemaItem{Name: *row[0], Kind: kind}
		if row[2] != nil {
			if n, err := strconv.ParseInt(*row[2], 10, 64); err == nil {
				item.Rows = &n
			}
		}
		g.Items = append(g.Items, item)
	}
	return &Result{Status: state.CommandOK, Truncated: r.Truncated, Schema: &SchemaInfo{Engine: engine, Groups: []SchemaGroup{g}}}
}

func (m *mysql) rows(ctx context.Context, t state.TableRef, page, size int) *Result {
	if err := t.Name.Validate(); err != nil {
		return refused("That is not a valid table name.")
	}
	sql := "SELECT * FROM " + quoteIdent(t.Name, "`") + " LIMIT " + strconv.Itoa(size) + " OFFSET " + strconv.Itoa(page*size)
	return m.exec(ctx, sql, true)
}
