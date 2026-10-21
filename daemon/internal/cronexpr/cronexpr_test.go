package cronexpr

import (
	"testing"
	"time"
)

func TestParseRefusesWhatTheGrammarDoesNotHave(t *testing.T) {
	for _, expr := range []string{
		"", "* * * *", "* * * * * *", "*  * * * *", " * * * * *", "60 * * * *", "* 24 * * *",
		"* * 0 * *", "* * * 13 *", "* * * * 8", "*/0 * * * *", "5-1 * * * *", "@daily",
		"MON * * * *", "* * * JAN *", "? * * * *", "1,,2 * * * *", "-1 * * * *", "001 * * * *",
		"* * L * *", "*/60 * * * *", "1-2-3 * * * *",
	} {
		if _, err := Parse(expr); err == nil {
			t.Errorf("Parse(%q) accepted", expr)
		}
	}
	for _, expr := range []string{
		"* * * * *", "*/5 * * * *", "0 3 * * *", "0 9 * * 1-5", "30 2 1 * *", "0 0 * * 7",
		"0,15,30,45 * * * *", "5/10 * * * *", "0 8-18/2 * * *", "0 0 29 2 *",
	} {
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
	eq(t, next5(t, "*/5 * * * *", "UTC", "2026-10-19T10:03:30Z"), []string{
		"2026-10-19T10:05:00Z", "2026-10-19T10:10:00Z", "2026-10-19T10:15:00Z", "2026-10-19T10:20:00Z", "2026-10-19T10:25:00Z",
	})
	eq(t, next5(t, "0 3 * * *", "Europe/Berlin", "2026-10-19T10:00:00Z"), []string{
		"2026-10-20T03:00:00+02:00", "2026-10-21T03:00:00+02:00", "2026-10-22T03:00:00+02:00", "2026-10-23T03:00:00+02:00", "2026-10-24T03:00:00+02:00",
	})
	// Monday or the 1st (both restricted: either matches).
	eq(t, next5(t, "0 0 1 * 1", "UTC", "2026-10-25T00:00:00Z"), []string{
		"2026-10-26T00:00:00Z", "2026-11-01T00:00:00Z", "2026-11-02T00:00:00Z", "2026-11-09T00:00:00Z", "2026-11-16T00:00:00Z",
	})
	// Leap days only.
	eq(t, next5(t, "0 12 29 2 *", "UTC", "2026-01-01T00:00:00Z"), []string{"2028-02-29T12:00:00Z", "2032-02-29T12:00:00Z", "2036-02-29T12:00:00Z", "2040-02-29T12:00:00Z", "2044-02-29T12:00:00Z"})
	// Never.
	eq(t, next5(t, "0 0 30 2 *", "UTC", "2026-01-01T00:00:00Z"), nil)
}

func TestNextAcrossDaylightSaving(t *testing.T) {
	// 2026-03-29 Berlin: 02:00 → 03:00; 02:30 doesn't exist and is skipped.
	eq(t, next5(t, "30 2 * * *", "Europe/Berlin", "2026-03-27T12:00:00Z"), []string{
		"2026-03-28T02:30:00+01:00", "2026-03-30T02:30:00+02:00", "2026-03-31T02:30:00+02:00", "2026-04-01T02:30:00+02:00", "2026-04-02T02:30:00+02:00",
	})
	// 2026-10-25 Berlin: 03:00 → 02:00; 02:30 happens twice and runs once.
	eq(t, next5(t, "30 2 * * *", "Europe/Berlin", "2026-10-23T12:00:00Z"), []string{
		"2026-10-24T02:30:00+02:00", "2026-10-25T02:30:00+02:00", "2026-10-26T02:30:00+01:00", "2026-10-27T02:30:00+01:00", "2026-10-28T02:30:00+01:00",
	})
}

func TestLoadLocation(t *testing.T) {
	for _, ok := range []string{"UTC", "Europe/Berlin", "America/Argentina/Buenos_Aires"} {
		if _, err := LoadLocation(ok); err != nil {
			t.Errorf("%s: %v", ok, err)
		}
	}
	for _, bad := range []string{"", "Local", "Mars/Olympus", "../etc/passwd"} {
		if _, err := LoadLocation(bad); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
}
