package session

import (
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func strp(v string) *string { return &v }

func TestPgSchemaInfoCountsSmallTables(t *testing.T) {
	r := &Result{Status: state.CommandOK, Rows: [][]*string{
		// A fresh table: reltuples -1 until analyzed.
		{strp("public"), strp("notes"), strp("r"), strp("-1"), strp("8192"), strp("t")},
		{strp("public"), strp("events"), strp("r"), strp("1200000"), strp("900000000"), strp("t")},
		{strp("public"), strp("recent"), strp("v"), nil, nil, strp("f")},
		{strp("public"), strp("secret"), strp("r"), strp("0"), strp("8192"), strp("f")},
		{strp("public"), strp(`bad"name`), strp("r"), strp("0"), strp("8192"), strp("t")},
	}}
	info, targets := pgSchemaInfo(r)
	items := info.Groups[0].Items
	if len(items) != 5 {
		t.Fatalf("items %+v", items)
	}
	if items[0].Rows != nil || items[1].Rows == nil || *items[1].Rows != 1200000 || !items[1].Estimate {
		t.Fatalf("estimates %+v %+v", items[0], items[1])
	}
	if items[2].Rows != nil || items[2].Estimate {
		t.Fatalf("view %+v", items[2])
	}
	picked := pickCounts(targets)
	if len(picked) != 1 || picked[0].table != "notes" {
		t.Fatalf("picked %+v", picked)
	}
	if sql := countSQL(picked, `"`); sql != `SELECT 0 AS i, count(*) AS n FROM "public"."notes"` {
		t.Fatalf("sql %q", sql)
	}
	applyCounts(info, picked, &Result{Status: state.CommandOK, Rows: [][]*string{{strp("0"), strp("6")}}})
	if items[0].Rows == nil || *items[0].Rows != 6 || items[0].Estimate {
		t.Fatalf("counted %+v", items[0])
	}
}

func TestApplyCountsKeepsEstimatesOnFailure(t *testing.T) {
	n := int64(40)
	info := &SchemaInfo{Groups: []SchemaGroup{{Items: []SchemaItem{{Name: "a", Rows: &n, Estimate: true}}}}}
	ts := []countTarget{{table: "a"}}
	applyCounts(info, ts, failed("canceling statement due to statement timeout"))
	applyCounts(info, ts, &Result{Status: state.CommandOK, Rows: [][]*string{{strp("7"), strp("1")}, {strp("0"), nil}}})
	if it := info.Groups[0].Items[0]; *it.Rows != 40 || !it.Estimate {
		t.Fatalf("item %+v", it)
	}
}

func TestPickCountsBudget(t *testing.T) {
	var ts []countTarget
	for i := range 10 {
		ts = append(ts, countTarget{item: i, table: "t", size: 60 << 20})
	}
	ts = append(ts, countTarget{item: 10, table: "u", size: -1}, countTarget{item: 11, table: "small", size: 0})
	picked := pickCounts(ts)
	if len(picked) != 5 || picked[0].table != "small" {
		t.Fatalf("picked %d %+v", len(picked), picked[0])
	}
	var many []countTarget
	for i := range 300 {
		many = append(many, countTarget{item: i, table: "t", size: 16384})
	}
	if got := len(pickCounts(many)); got != countMaxTables {
		t.Fatalf("max tables %d", got)
	}
}

func TestMySQLSchemaInfo(t *testing.T) {
	r := &Result{Status: state.CommandOK, Rows: [][]*string{
		{strp("orders"), strp("BASE TABLE"), strp("0"), strp("16384")},
		{strp("big"), strp("BASE TABLE"), strp("5100000"), strp("700000000")},
		{strp("v"), strp("VIEW"), nil, nil},
	}}
	info, targets := mysqlSchemaInfo(r, "app", state.EngineMySQL)
	items := info.Groups[0].Items
	if info.Groups[0].Name != "app" || len(items) != 3 || items[0].Rows != nil || !items[1].Estimate || items[2].Kind != "view" {
		t.Fatalf("info %+v", info)
	}
	picked := pickCounts(targets)
	if len(picked) != 1 || countSQL(picked, "`") != "SELECT 0 AS i, count(*) AS n FROM `orders`" {
		t.Fatalf("picked %+v", picked)
	}
}
