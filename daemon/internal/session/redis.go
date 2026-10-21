package session

import (
	"context"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Redis/Valkey console command lists (docs/03 §11.4).
var (
	redisDenied = setOf("SHUTDOWN", "DEBUG", "MONITOR", "SYNC", "PSYNC", "REPLICAOF", "SLAVEOF", "FAILOVER",
		"MODULE", "CONFIG", "ACL", "SUBSCRIBE", "PSUBSCRIBE", "SSUBSCRIBE", "CLIENT", "SCRIPT", "FUNCTION",
		"BGREWRITEAOF", "BGSAVE", "SAVE", "CLUSTER", "QUIT", "RESET")
	redisReadOnly = setOf("GET", "MGET", "EXISTS", "TYPE", "TTL", "PTTL", "STRLEN", "GETRANGE", "SCAN", "KEYS",
		"DBSIZE", "INFO", "HGET", "HGETALL", "HMGET", "HLEN", "HKEYS", "HVALS", "HSCAN", "LRANGE", "LLEN", "LINDEX",
		"SMEMBERS", "SISMEMBER", "SCARD", "SSCAN", "ZRANGE", "ZRANGEBYSCORE", "ZSCORE", "ZCARD", "ZSCAN",
		"XRANGE", "XREVRANGE", "XLEN", "XINFO", "OBJECT", "MEMORY", "PING", "ECHO", "TIME")
)

func setOf(v ...string) map[string]bool {
	m := make(map[string]bool, len(v))
	for _, s := range v {
		m[s] = true
	}
	return m
}

// redisCommandAllowed checks a console command line's first word; the
// refusal is a sentence for the user ("" = allowed).
func redisCommandAllowed(args []string, allowWrites bool) string {
	if len(args) == 0 {
		return "Type a command, for example GET mykey."
	}
	cmd := strings.ToUpper(args[0])
	if redisDenied[cmd] {
		return cmd + " is not available in the data console."
	}
	if !allowWrites && !redisReadOnly[cmd] {
		return cmd + ` may change data. Turn on "Allow writes" to run it.`
	}
	return ""
}

// splitRedisArgs tokenizes a command line like redis-cli: whitespace
// separated, "double quotes" with \n \r \t \b \a \\ \" \xHH, 'single quotes' with \'.
func splitRedisArgs(line string) ([]string, error) {
	var out []string
	i := 0
	for {
		for i < len(line) && (line[i] == ' ' || line[i] == '\t' || line[i] == '\n' || line[i] == '\r') {
			i++
		}
		if i >= len(line) {
			return out, nil
		}
		var cur strings.Builder
		switch line[i] {
		case '"':
			i++
			for {
				if i >= len(line) {
					return nil, errors.New("unbalanced quotes")
				}
				c := line[i]
				if c == '"' {
					i++
					break
				}
				if c == '\\' && i+1 < len(line) {
					i++
					switch line[i] {
					case 'n':
						cur.WriteByte('\n')
					case 'r':
						cur.WriteByte('\r')
					case 't':
						cur.WriteByte('\t')
					case 'b':
						cur.WriteByte('\b')
					case 'a':
						cur.WriteByte('\a')
					case 'x':
						if i+2 < len(line) {
							if v, err := strconv.ParseUint(line[i+1:i+3], 16, 8); err == nil {
								cur.WriteByte(byte(v))
								i += 3
								continue
							}
						}
						cur.WriteByte('x')
					default:
						cur.WriteByte(line[i])
					}
					i++
					continue
				}
				cur.WriteByte(c)
				i++
			}
		case '\'':
			i++
			for {
				if i >= len(line) {
					return nil, errors.New("unbalanced quotes")
				}
				c := line[i]
				if c == '\'' {
					i++
					break
				}
				if c == '\\' && i+1 < len(line) && line[i+1] == '\'' {
					cur.WriteByte('\'')
					i += 2
					continue
				}
				cur.WriteByte(c)
				i++
			}
		default:
			for i < len(line) && line[i] != ' ' && line[i] != '\t' && line[i] != '\n' && line[i] != '\r' {
				cur.WriteByte(line[i])
				i++
			}
			out = append(out, cur.String())
			continue
		}
		if i < len(line) && line[i] != ' ' && line[i] != '\t' && line[i] != '\n' && line[i] != '\r' {
			return nil, errors.New("closing quote must be followed by a space")
		}
		out = append(out, cur.String())
	}
}

type redis struct {
	p   proc
	cli string
}

func newRedis(env envMap, ex execer, container, cli string) (*redis, error) {
	pw, err := password(env, "REDIS_PASSWORD")
	if err != nil {
		return nil, err
	}
	return &redis{p: newProc(ex, container, "REDISCLI_AUTH="+pw), cli: cli}, nil
}

func (r *redis) call(ctx context.Context, args ...string) (jsontext.Value, *Result) {
	argv := append([]string{r.cli, "--no-auth-warning", "--json"}, args...)
	pr, err := r.p.run(ctx, argv, 0)
	if err != nil {
		return nil, failed(err.Error())
	}
	if pr.truncated {
		return nil, &Result{Status: state.CommandOK, Truncated: true, Message: "The reply is larger than 2 MiB."}
	}
	out := strings.TrimSpace(string(pr.out))
	if pr.code != 0 && out == "" {
		return nil, failed(firstNonEmpty(pr.stderr, "The command failed."))
	}
	if strings.HasPrefix(out, "(error)") || strings.HasPrefix(out, `"(error)`) || strings.HasPrefix(out, "ERR") {
		return nil, failed(r.p.mask.Mask(strings.Trim(out, `"`)))
	}
	v := jsontext.Value(out)
	if !v.IsValid() {
		return nil, failed(r.p.mask.Mask(clip(out)))
	}
	return v, nil
}

func firstNonEmpty(v ...string) string {
	for _, s := range v {
		if s != "" {
			return s
		}
	}
	return ""
}

func (r *redis) query(ctx context.Context, statement string, allowWrites bool) *Result {
	args, err := splitRedisArgs(statement)
	if err != nil {
		return failed("Could not read the command: " + err.Error() + ".")
	}
	if msg := redisCommandAllowed(args, allowWrites); msg != "" {
		return refused(msg)
	}
	v, fail := r.call(ctx, args...)
	if fail != nil {
		return fail
	}
	return &Result{Status: state.CommandOK, Reply: v}
}

func (r *redis) schema(ctx context.Context) *Result {
	v, fail := r.call(ctx, "DBSIZE")
	if fail != nil {
		return fail
	}
	var n int64
	if err := json.Unmarshal(v, &n); err != nil {
		return failed("Unexpected DBSIZE reply.")
	}
	engine := state.EngineRedis
	if r.cli == "valkey-cli" {
		engine = state.EngineValkey
	}
	return &Result{Status: state.CommandOK, Schema: &SchemaInfo{Engine: engine, Groups: []SchemaGroup{{Name: "db0", Items: []SchemaItem{}, Rows: &n}}}}
}

const scanScript = `local r = redis.call('SCAN', ARGV[1], 'MATCH', ARGV[2], 'COUNT', ARGV[3])
local out = {r[1]}
for _, k in ipairs(r[2]) do
  out[#out+1] = k
  out[#out+1] = redis.call('TYPE', k)['ok']
  out[#out+1] = redis.call('PTTL', k)
end
return out`

func (r *redis) keys(ctx context.Context, pattern, cursor string) *Result {
	if pattern == "" {
		pattern = "*"
	}
	if cursor == "" {
		cursor = "0"
	}
	v, fail := r.call(ctx, "EVAL_RO", scanScript, "0", cursor, pattern, "100")
	if fail != nil {
		return fail
	}
	var raw []jsontext.Value
	if err := json.Unmarshal(v, &raw); err != nil || len(raw) < 1 {
		return failed("Unexpected SCAN reply.")
	}
	page := &KeyPage{Cursor: jsonString(raw[0]), Items: []KeyItem{}}
	for i := 1; i+2 < len(raw); i += 3 {
		page.Items = append(page.Items, KeyItem{Key: jsonString(raw[i]), Type: jsonString(raw[i+1]), TTLMs: jsonInt(raw[i+2])})
	}
	return &Result{Status: state.CommandOK, Keys: page}
}

const keyScript = `local k = KEYS[1]
local t = redis.call('TYPE', k)['ok']
local ttl = redis.call('PTTL', k)
if t == 'string' then
  return {t, ttl, redis.call('STRLEN', k), redis.call('GETRANGE', k, 0, 65535)}
elseif t == 'hash' then
  return {t, ttl, redis.call('HLEN', k), redis.call('HSCAN', k, 0, 'COUNT', 200)[2]}
elseif t == 'list' then
  return {t, ttl, redis.call('LLEN', k), redis.call('LRANGE', k, 0, 199)}
elseif t == 'set' then
  return {t, ttl, redis.call('SCARD', k), redis.call('SSCAN', k, 0, 'COUNT', 200)[2]}
elseif t == 'zset' then
  return {t, ttl, redis.call('ZCARD', k), redis.call('ZRANGE', k, 0, 199, 'WITHSCORES')}
elseif t == 'stream' then
  return {t, ttl, redis.call('XLEN', k), redis.call('XRANGE', k, '-', '+', 'COUNT', 50)}
end
return {t, ttl, 0, {}}`

const maxItems = 200

func (r *redis) key(ctx context.Context, key string) *Result {
	v, fail := r.call(ctx, "EVAL_RO", keyScript, "1", key)
	if fail != nil {
		return fail
	}
	return parseKeyReply(key, v)
}

func parseKeyReply(key string, v jsontext.Value) *Result {
	var raw []jsontext.Value
	if err := json.Unmarshal(v, &raw); err != nil || len(raw) != 4 {
		return failed("Unexpected reply.")
	}
	kv := &KeyValue{Key: key, Type: jsonString(raw[0]), TTLMs: jsonInt(raw[1]), Size: jsonInt(raw[2])}
	switch kv.Type {
	case "none":
		return &Result{Status: state.CommandError, Message: "This key does not exist (any more)."}
	case "string":
		var cut bool
		kv.Text = cell(jsonString(raw[3]), &cut)
		if cut || kv.Size > 65536 {
			kv.Truncated = true
		}
		return &Result{Status: state.CommandOK, Value: kv}
	}
	var items []jsontext.Value
	_ = json.Unmarshal(raw[3], &items)
	switch kv.Type {
	case "list", "set":
		for _, it := range items {
			if len(kv.Items) >= maxItems {
				break
			}
			kv.Items = append(kv.Items, jsonString(it))
		}
		kv.Truncated = kv.Size > int64(len(kv.Items))
	case "hash", "zset":
		for i := 0; i+1 < len(items) && len(kv.Entries) < maxItems; i += 2 {
			kv.Entries = append(kv.Entries, []string{jsonString(items[i]), jsonString(items[i+1])})
		}
		kv.Truncated = kv.Size > int64(len(kv.Entries))
	case "stream":
		for _, it := range items {
			var e []jsontext.Value
			if json.Unmarshal(it, &e) != nil || len(e) != 2 {
				continue
			}
			kv.Entries = append(kv.Entries, []string{jsonString(e[0]), string(e[1])})
		}
		kv.Truncated = kv.Size > int64(len(kv.Entries))
	}
	return &Result{Status: state.CommandOK, Value: kv}
}

// jsonString renders a JSON scalar as text (strings unquoted).
func jsonString(v jsontext.Value) string {
	var s string
	if json.Unmarshal(v, &s) == nil {
		return s
	}
	return strings.TrimSpace(string(v))
}

func jsonInt(v jsontext.Value) int64 {
	var n int64
	if json.Unmarshal(v, &n) == nil {
		return n
	}
	n, _ = strconv.ParseInt(jsonString(v), 10, 64)
	return n
}

func (r *redis) rows(context.Context, state.TableRef, int, int) *Result {
	return refused("Redis and Valkey have keys, not tables.")
}
