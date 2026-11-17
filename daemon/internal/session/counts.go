package session

import (
	"cmp"
	"slices"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Row counts in the schema list (docs/03 §11.4). The catalogs only keep
// estimates (pg_class.reltuples, InnoDB's table_rows), and those stay 0 or
// unknown until the table is analyzed: a fresh table with six rows showed
// "0". Tables that are small on disk are counted exactly with one bounded
// count(*) statement; the rest keep the catalog figure, marked as an estimate.
const (
	countTableMaxBytes = 64 << 20
	countBudgetBytes   = 256 << 20
	countMaxTables     = 100
	countTimeoutMs     = 5000
)

// countTarget is a table that may be counted exactly.
type countTarget struct {
	group, item   int
	schema, table state.TableName
	size          int64
}

// countable reports whether the names can be quoted (the browsing grammar).
func countable(schema, table string) bool {
	if schema != "" && state.TableName(schema).Validate() != nil {
		return false
	}
	return state.TableName(table).Validate() == nil
}

// pickCounts takes the smallest tables first, within the per-table and total limits.
func pickCounts(ts []countTarget) []countTarget {
	ts = slices.Clone(ts)
	slices.SortStableFunc(ts, func(a, b countTarget) int { return cmp.Compare(a.size, b.size) })
	var out []countTarget
	var total int64
	for _, t := range ts {
		if t.size < 0 {
			continue // size unknown
		}
		if t.size > countTableMaxBytes || total+t.size > countBudgetBytes || len(out) == countMaxTables {
			break
		}
		total += t.size
		out = append(out, t)
	}
	return out
}

// countSQL is one UNION ALL of count(*) per target; q is the identifier quote.
func countSQL(ts []countTarget, q string) string {
	parts := make([]string, len(ts))
	for i, t := range ts {
		from := quoteIdent(t.table, q)
		if t.schema != "" {
			from = quoteIdent(t.schema, q) + "." + from
		}
		parts[i] = "SELECT " + strconv.Itoa(i) + " AS i, count(*) AS n FROM " + from
	}
	return strings.Join(parts, " UNION ALL ")
}

// applyCounts writes the exact counts of a countSQL result into info. A
// failed count (timeout, a lock, no privilege) keeps the estimates.
func applyCounts(info *SchemaInfo, ts []countTarget, r *Result) {
	if r == nil || r.Status != state.CommandOK {
		return
	}
	for _, row := range r.Rows {
		if len(row) != 2 || row[0] == nil || row[1] == nil {
			continue
		}
		i, err := strconv.Atoi(*row[0])
		if err != nil || i < 0 || i >= len(ts) {
			continue
		}
		n, err := strconv.ParseInt(*row[1], 10, 64)
		if err != nil || n < 0 {
			continue
		}
		it := &info.Groups[ts[i].group].Items[ts[i].item]
		it.Rows, it.Estimate = &n, false
	}
}

// estimate is a catalog row estimate; zero or negative means unknown.
func estimate(v *string) *int64 {
	if v == nil {
		return nil
	}
	n, err := strconv.ParseFloat(*v, 64)
	if err != nil || n < 1 {
		return nil
	}
	i := int64(n)
	return &i
}
