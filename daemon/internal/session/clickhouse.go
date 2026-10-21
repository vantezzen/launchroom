package session

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// clickhouse runs statements with clickhouse-client inside the database
// container (D47). Credentials only in the exec env (CLICKHOUSE_USER,
// CLICKHOUSE_PASSWORD, which the client reads); results come as
// JSONCompactStringsEachRowWithNames, one JSON array per line. Read-only
// mode is the server's own `readonly=1` for the query; every query also
// has max_execution_time and a result row cap.
type clickhouse struct {
	sqlBase
	p  proc
	db string
}

func newClickHouse(env envMap, ex execer, container string) (*clickhouse, error) {
	user, err := ident(env, "default", "CLICKHOUSE_USER")
	if err != nil {
		return nil, err
	}
	db, err := ident(env, "default", "CLICKHOUSE_DATABASE", "CLICKHOUSE_DB")
	if err != nil {
		return nil, err
	}
	creds := []string{"CLICKHOUSE_USER=" + user}
	if pw := env["CLICKHOUSE_PASSWORD"]; !pw.IsZero() {
		creds = append(creds, "CLICKHOUSE_PASSWORD="+pw.Reveal())
	}
	return &clickhouse{p: newProc(ex, container, creds...), db: db}, nil
}

func (c *clickhouse) argv(statement string, readOnly bool) []string {
	argv := []string{
		"clickhouse-client", "--host=127.0.0.1", "--database=" + c.db,
		"--format=JSONCompactStringsEachRowWithNames",
		"--max_execution_time=" + strconv.Itoa(statementTimeoutMs/1000),
		"--max_result_rows=" + strconv.Itoa(MaxRows+1), "--result_overflow_mode=break",
	}
	if readOnly {
		// Last: settings sent with the query are applied before readonly
		// takes effect for the query itself.
		argv = append(argv, "--readonly=1")
	}
	return append(argv, "--query", statement)
}

func (c *clickhouse) exec(ctx context.Context, statement string, readOnly bool) *Result {
	r, err := c.p.run(ctx, c.argv(statement, readOnly), MaxRows+2)
	if err != nil {
		return failed(err.Error())
	}
	if r.code != 0 && !r.truncated {
		return clickhouseFailure(r.stderr)
	}
	return parseClickHouseRows(r.out, r.truncated)
}

// clickhouseFailure maps a client error; readonly refusals are "refused".
func clickhouseFailure(msg string) *Result {
	if msg == "" {
		msg = "The statement failed."
	}
	// "Code: 164. DB::Exception: Received from …: DB::Exception: app: Cannot …"
	if i := strings.LastIndex(msg, "DB::Exception: "); i >= 0 {
		msg = msg[i+len("DB::Exception: "):]
	}
	l := strings.ToLower(msg)
	if strings.Contains(l, "readonly mode") || strings.Contains(l, "(readonly)") {
		return &Result{Status: state.CommandRefused, Message: msg}
	}
	return failed(msg)
}

// parseClickHouseRows reads JSONCompactStringsEachRowWithNames: a names
// array, then one array of strings (or null) per row.
func parseClickHouseRows(out []byte, cut bool) *Result {
	res := &Result{Status: state.CommandOK, Truncated: cut}
	sc := bufio.NewScanner(bytes.NewReader(out))
	sc.Buffer(make([]byte, 64<<10), 2*MaxResult)
	for sc.Scan() {
		line := bytes.TrimSpace(sc.Bytes())
		if len(line) == 0 {
			continue
		}
		if res.Columns == nil {
			var names []string
			if err := json.Unmarshal(line, &names); err != nil {
				res.Truncated = true
				break
			}
			res.Columns, res.Rows = names, [][]*string{}
			continue
		}
		if len(res.Rows) >= MaxRows {
			res.Truncated = true
			break
		}
		var vals []jsontext.Value
		if err := json.Unmarshal(line, &vals); err != nil {
			res.Truncated = true
			break
		}
		row := make([]*string, len(res.Columns))
		for i := range row {
			if i >= len(vals) || string(vals[i]) == "null" {
				continue
			}
			var s string
			if json.Unmarshal(vals[i], &s) != nil {
				s = string(vals[i])
			}
			row[i] = cell(s, &res.CellsTruncated)
		}
		res.Rows = append(res.Rows, row)
	}
	if res.Columns == nil {
		res.Message = "The statement ran."
	}
	return res
}

func (c *clickhouse) query(ctx context.Context, statement string, allowWrites bool) *Result {
	return c.exec(ctx, statement, !allowWrites)
}

func (c *clickhouse) schema(ctx context.Context) *Result {
	r := c.exec(ctx, "SELECT name, engine, total_rows FROM system.tables WHERE database = currentDatabase() AND NOT is_temporary ORDER BY name LIMIT 2000", true)
	if r.Status != state.CommandOK {
		return r
	}
	g := SchemaGroup{Name: c.db, Items: []SchemaItem{}}
	for _, row := range r.Rows {
		if len(row) != 3 || row[0] == nil {
			continue
		}
		kind := "table"
		if row[1] != nil && strings.HasSuffix(*row[1], "View") {
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
	return &Result{Status: state.CommandOK, Truncated: r.Truncated, Schema: &SchemaInfo{Engine: state.EngineClickHouse, Groups: []SchemaGroup{g}}}
}

func (c *clickhouse) rows(ctx context.Context, t state.TableRef, page, size int) *Result {
	// Backslashes escape inside ClickHouse's backquotes: refused too.
	if err := t.Name.Validate(); err != nil || strings.ContainsRune(string(t.Name), '\\') {
		return refused("That is not a valid table name.")
	}
	sql := "SELECT * FROM " + quoteIdent(t.Name, "`") + " LIMIT " + strconv.Itoa(size) + " OFFSET " + strconv.Itoa(page*size)
	return c.exec(ctx, sql, true)
}
