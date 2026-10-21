package session

import (
	"bytes"
	"context"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// mongo browses and queries MongoDB with mongosh inside the database
// container (D47). The script is a fixed string; everything variable (the
// credentials, the collection, the command document) reaches it only
// through the exec env and is read with process.env, so nothing a user
// types is ever evaluated as JavaScript. The query console takes a
// database command as a (relaxed Extended) JSON document, e.g.
// {"find": "users", "filter": {"plan": "pro"}}, run with db.runCommand().
//
// Read-only mode is a command allow-list checked here before mongosh runs
// (find, aggregate without $out/$merge, count, distinct, list*/stats,
// explain); some commands are refused even with writes allowed (users,
// roles, shutdown, replication, server parameters, ...).
type mongo struct {
	sqlBase
	p  proc
	db string
}

func newMongo(env envMap, ex execer, container string) (*mongo, error) {
	user, err := ident(env, "", "MONGO_INITDB_ROOT_USERNAME")
	if err != nil {
		return nil, err
	}
	db, err := ident(env, "test", "MONGO_INITDB_DATABASE")
	if err != nil {
		return nil, err
	}
	pw, err := password(env, "MONGO_INITDB_ROOT_PASSWORD")
	if err != nil {
		return nil, err
	}
	return &mongo{p: newProc(ex, container, "LR_MONGO_USER="+user, "LR_MONGO_PASSWORD="+pw, "LR_MONGO_DB="+db), db: db}, nil
}

// mongoMarker prefixes the script's one result line.
const mongoMarker = "__lr_result__"

// mongoScript is the only JavaScript launchroom runs (fixed). Documents are
// printed as [[key, value], ...] with strings as-is, ObjectIds as hex, dates
// as ISO 8601 and everything else as relaxed Extended JSON, so key order is kept.
const mongoScript = `const env = process.env;
const uri = "mongodb://" + encodeURIComponent(env.LR_MONGO_USER) + ":" + encodeURIComponent(env.LR_MONGO_PASSWORD) +
  "@127.0.0.1:27017/?authSource=admin&directConnection=true&appName=launchroom";
const d = new Mongo(uri).getDB(env.LR_MONGO_DB);
const cellOf = (v) => v === null || v === undefined ? null : typeof v === "string" ? v
  : v._bsontype === "ObjectId" ? v.toHexString() : v instanceof Date ? v.toISOString() : EJSON.stringify(v, {relaxed: true});
const docOf = (doc) => Object.keys(doc).map((k) => [k, cellOf(doc[k])]);
const out = (o) => print("` + mongoMarker + `" + JSON.stringify(o));
try {
  const limit = parseInt(env.LR_MONGO_LIMIT || "50", 10);
  if (env.LR_MONGO_OP === "schema") {
    const items = [];
    for (const c of d.getCollectionInfos({}, {nameOnly: false, authorizedCollections: true}).slice(0, 2000)) {
      const it = {name: c.name, kind: c.type || "collection"};
      if (it.kind === "collection") { try { it.rows = d.getCollection(c.name).estimatedDocumentCount(); } catch (e) {} }
      items.push(it);
    }
    out({items});
  } else if (env.LR_MONGO_OP === "rows") {
    const docs = d.getCollection(env.LR_MONGO_COLLECTION).find({}).sort({_id: 1})
      .skip(parseInt(env.LR_MONGO_SKIP || "0", 10)).limit(limit + 1).toArray();
    out({docs: docs.slice(0, limit).map(docOf), more: docs.length > limit});
  } else {
    const r = d.runCommand(EJSON.parse(env.LR_MONGO_COMMAND, {relaxed: false}));
    if (r && r.ok === 1 && r.cursor && Array.isArray(r.cursor.firstBatch)) {
      const more = r.cursor.id && r.cursor.id.toString() !== "0";
      if (more) { try { d.runCommand({killCursors: r.cursor.ns.split(".").slice(1).join("."), cursors: [r.cursor.id]}); } catch (e) {} }
      out({docs: r.cursor.firstBatch.slice(0, limit).map(docOf), more: more || r.cursor.firstBatch.length > limit});
    } else {
      out({reply: EJSON.stringify(r, {relaxed: true})});
    }
  }
} catch (e) {
  out({error: String(e && e.message ? e.message : e).slice(0, 500)});
}`

type mongoOutput struct {
	Items []struct {
		Name string `json:"name"`
		Kind string `json:"kind"`
		Rows *int64 `json:"rows"`
	} `json:"items"`
	Docs  [][][]*string `json:"docs"`
	More  bool          `json:"more"`
	Reply *string       `json:"reply"`
	Error string        `json:"error"`
}

func (m *mongo) run(ctx context.Context, extra ...string) (*mongoOutput, *Result) {
	argv := []string{"mongosh", "--quiet", "--nodb", "--norc", "--eval", mongoScript}
	r, err := m.p.run(ctx, argv, 0, extra...)
	if err != nil {
		return nil, failed(err.Error())
	}
	if r.truncated {
		return nil, &Result{Status: state.CommandOK, Truncated: true, Message: "The result is larger than 1 MiB."}
	}
	var line []byte
	for l := range bytes.SplitSeq(r.out, []byte("\n")) {
		if i := bytes.Index(l, []byte(mongoMarker)); i >= 0 {
			line = l[i+len(mongoMarker):]
		}
	}
	if line == nil {
		if r.stderr != "" {
			return nil, failed(r.stderr)
		}
		return nil, failed("mongosh exited with code " + strconv.Itoa(r.code) + ".")
	}
	var o mongoOutput
	if err := json.Unmarshal(line, &o); err != nil {
		return nil, failed("Unexpected mongosh output.")
	}
	if o.Error != "" {
		return nil, failed(m.p.mask.Mask(o.Error))
	}
	return &o, nil
}

// documents turns documents into columns (keys in order of first
// appearance, _id first) and rows.
func documents(docs [][][]*string, more bool) *Result {
	res := &Result{Status: state.CommandOK, Truncated: more, Columns: []string{}, Rows: [][]*string{}}
	index := map[string]int{}
	for _, d := range docs {
		for _, kv := range d {
			if len(kv) == 2 && kv[0] != nil {
				if _, ok := index[*kv[0]]; !ok {
					index[*kv[0]] = len(res.Columns)
					res.Columns = append(res.Columns, *kv[0])
				}
			}
		}
	}
	if i, ok := index["_id"]; ok && i != 0 {
		res.Columns = append([]string{"_id"}, append(res.Columns[:i:i], res.Columns[i+1:]...)...)
		for n, c := range res.Columns {
			index[c] = n
		}
	}
	for _, d := range docs {
		if len(res.Rows) >= MaxRows {
			res.Truncated = true
			break
		}
		row := make([]*string, len(res.Columns))
		for _, kv := range d {
			if len(kv) == 2 && kv[0] != nil && kv[1] != nil {
				row[index[*kv[0]]] = cell(*kv[1], &res.CellsTruncated)
			}
		}
		res.Rows = append(res.Rows, row)
	}
	return res
}

func (m *mongo) schema(ctx context.Context) *Result {
	o, fail := m.run(ctx, "LR_MONGO_OP=schema")
	if fail != nil {
		return fail
	}
	g := SchemaGroup{Name: m.db, Items: []SchemaItem{}}
	for _, it := range o.Items {
		if state.TableName(it.Name).Validate() != nil {
			continue // only names the browser can address again
		}
		kind := it.Kind
		if kind != "view" && kind != "timeseries" {
			kind = "collection"
		}
		g.Items = append(g.Items, SchemaItem{Name: it.Name, Kind: kind, Rows: it.Rows})
	}
	return &Result{Status: state.CommandOK, Schema: &SchemaInfo{Engine: state.EngineMongoDB, Groups: []SchemaGroup{g}}}
}

func (m *mongo) rows(ctx context.Context, t state.TableRef, page, size int) *Result {
	if err := t.Name.Validate(); err != nil {
		return refused("That is not a valid collection name.")
	}
	o, fail := m.run(ctx, "LR_MONGO_OP=rows", "LR_MONGO_COLLECTION="+string(t.Name),
		"LR_MONGO_SKIP="+strconv.Itoa(page*size), "LR_MONGO_LIMIT="+strconv.Itoa(size))
	if fail != nil {
		return fail
	}
	return documents(o.Docs, o.More)
}

func (m *mongo) query(ctx context.Context, statement string, allowWrites bool) *Result {
	if err := checkMongoCommand(statement, allowWrites); err != nil {
		return refused(err.Error())
	}
	o, fail := m.run(ctx, "LR_MONGO_OP=command", "LR_MONGO_COMMAND="+statement, "LR_MONGO_LIMIT="+strconv.Itoa(MaxRows))
	if fail != nil {
		return fail
	}
	if o.Reply != nil {
		return &Result{Status: state.CommandOK, Reply: jsontext.Value(*o.Reply)}
	}
	return documents(o.Docs, o.More)
}

// Read-only commands (lower case); anything else needs "Allow writes".
var mongoReadCommands = map[string]bool{
	"find": true, "aggregate": true, "count": true, "distinct": true, "listcollections": true, "listindexes": true,
	"collstats": true, "dbstats": true, "explain": true, "ping": true, "buildinfo": true, "serverstatus": true,
	"datasize": true, "hello": true, "ismaster": true,
}

// Commands refused even with writes allowed: users and roles, the server,
// replication and sharding, sessions and operations of others, anything
// that reaches outside the database.
var mongoRefusedCommands = map[string]bool{
	"shutdown": true, "fsync": true, "fsyncunlock": true, "setparameter": true, "getparameter": true,
	"setfeaturecompatibilityversion": true, "setdefaultrwconcern": true, "logrotate": true, "getlog": true,
	"createuser": true, "updateuser": true, "dropuser": true, "dropallusersfromdatabase": true,
	"grantrolestouser": true, "revokerolesfromuser": true, "createrole": true, "updaterole": true, "droprole": true,
	"dropallrolesfromdatabase": true, "grantprivilegestorole": true, "revokeprivilegesfromrole": true,
	"grantrolestorole": true, "revokerolesfromrole": true, "usersinfo": true, "rolesinfo": true,
	"authenticate": true, "logout": true, "saslstart": true, "saslcontinue": true,
	"killop": true, "killallsessions": true, "killallsessionsbypattern": true, "killsessions": true, "currentop": true,
	"applyops": true, "eval": true, "copydb": true, "clone": true, "clonecollection": true, "renamecollection": true,
	"connectionstatus": true, "hostinfo": true, "getcmdlineopts": true,
}

// refusal is a user-facing reason a command is not run.
type refusal string

func (r refusal) Error() string { return string(r) }

const errNotDocument = refusal(`Type a database command as a JSON document, e.g. {"find": "users", "filter": {}}.`)

// checkMongoCommand parses the command document's first key (the command
// name) and applies the lists. Aggregations writing with $out/$merge need
// writes; "$db" (another database) is never allowed.
func checkMongoCommand(statement string, allowWrites bool) error {
	dec := jsontext.NewDecoder(strings.NewReader(statement))
	tok, err := dec.ReadToken()
	if err != nil || tok.Kind() != '{' {
		return errNotDocument
	}
	tok, err = dec.ReadToken()
	if err != nil || tok.Kind() != '"' {
		return errNotDocument
	}
	name := strings.ToLower(tok.String())
	// The whole document must be valid JSON (one value, nothing after it).
	var v jsontext.Value
	if err := json.Unmarshal([]byte(statement), &v); err != nil {
		return errNotDocument
	}
	writes := false
	if err := walkKeys(v, 0, func(k string, depth int) error {
		if depth == 0 && k == "$db" {
			return refusal("Commands run in this database only.")
		}
		if k == "$out" || k == "$merge" {
			writes = true
		}
		return nil
	}); err != nil {
		return err
	}
	switch {
	case mongoRefusedCommands[name]:
		return refusal("launchroom doesn’t run " + tok.String() + " from the Data tab.")
	case !allowWrites && (!mongoReadCommands[name] || writes):
		return refusal("This command can change data. Allow writes to run it.")
	}
	return nil
}

// walkKeys calls fn for every object key (with its nesting depth).
func walkKeys(v jsontext.Value, depth int, fn func(string, int) error) error {
	if depth > 64 {
		return refusal("The command is nested too deeply.")
	}
	switch v.Kind() {
	case '{':
		var m map[string]jsontext.Value
		if err := json.Unmarshal(v, &m, jsontext.AllowDuplicateNames(false)); err != nil {
			return errNotDocument
		}
		for k, c := range m {
			if err := fn(k, depth); err != nil {
				return err
			}
			if err := walkKeys(c, depth+1, fn); err != nil {
				return err
			}
		}
	case '[':
		var a []jsontext.Value
		if err := json.Unmarshal(v, &a); err != nil {
			return errNotDocument
		}
		for _, c := range a {
			if err := walkKeys(c, depth+1, fn); err != nil {
				return err
			}
		}
	}
	return nil
}
