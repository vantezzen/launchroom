package cronexpr

import (
	"encoding/json"
	"os"
	"testing"
	"time"
)

// vectors are the shared cron vectors (protocol/fixtures/cron.json) the
// dashboard's PHP and TS ports read too.
type vectors struct {
	Accept []string `json:"accept"`
	Refuse []string `json:"refuse"`
	Next   []struct {
		Cron     string   `json:"cron"`
		TimeZone string   `json:"timeZone"`
		From     string   `json:"from"`
		Runs     []string `json:"runs"`
	} `json:"next"`
	TimeZones struct {
		Valid   []string `json:"valid"`
		Invalid []string `json:"invalid"`
	} `json:"timeZones"`
}

func loadVectors(t *testing.T) vectors {
	t.Helper()
	data, err := os.ReadFile("../../../protocol/fixtures/cron.json")
	if err != nil {
		t.Fatal(err)
	}
	var v vectors
	if err := json.Unmarshal(data, &v); err != nil {
		t.Fatal(err)
	}
	if len(v.Accept) == 0 || len(v.Refuse) == 0 || len(v.Next) == 0 {
		t.Fatal("empty cron vectors")
	}
	return v
}

func TestParseRefusesWhatTheGrammarDoesNotHave(t *testing.T) {
	v := loadVectors(t)
	for _, expr := range v.Refuse {
		if _, err := Parse(expr); err == nil {
			t.Errorf("Parse(%q) accepted", expr)
		}
	}
	for _, expr := range v.Accept {
		if _, err := Parse(expr); err != nil {
			t.Errorf("Parse(%q): %v", expr, err)
		}
	}
}

func next5(t *testing.T, expr, zone, from string) []string {
	t.Helper()
	s, err := Parse(expr)
	if err != nil {
		t.Fatal(err)
	}
	loc, err := LoadLocation(zone)
	if err != nil {
		t.Fatal(err)
	}
	at, err := time.Parse(time.RFC3339, from)
	if err != nil {
		t.Fatal(err)
	}
	var out []string
	for range 5 {
		at = s.Next(at, loc)
		if at.IsZero() {
			break
		}
		out = append(out, at.Format(time.RFC3339))
	}
	return out
}

func eq(t *testing.T, got, want []string) {
	t.Helper()
	if len(got) != len(want) {
		t.Fatalf("got %v, want %v", got, want)
	}
	for i := range got {
		if got[i] != want[i] {
			t.Fatalf("got %v, want %v", got, want)
		}
	}
}

func TestNext(t *testing.T) {
	for _, n := range loadVectors(t).Next {
		eq(t, next5(t, n.Cron, n.TimeZone, n.From), n.Runs)
	}
}

func TestLoadLocation(t *testing.T) {
	v := loadVectors(t)
	for _, ok := range v.TimeZones.Valid {
		if _, err := LoadLocation(ok); err != nil {
			t.Errorf("%s: %v", ok, err)
		}
	}
	for _, bad := range v.TimeZones.Invalid {
		if _, err := LoadLocation(bad); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
}
