// Package cronexpr parses the 5-field cron expressions of scheduled cron
// services (WorkloadSpec.schedule, docs/04 §1) and matches them against wall
// clock time in a time zone. The grammar is deliberately small and the same
// on both sides (dashboard App\Support\Cron\CronExpression):
//
//	field   = item { "," item }
//	item    = ( "*" | number | number "-" number ) [ "/" step ]
//
// minute 0-59, hour 0-23, day of month 1-31, month 1-12, day of week 0-7
// (0 and 7 are Sunday). No names, no macros, no "?", "L" or "W". As in
// Vixie cron, when both day of month and day of week are restricted (neither
// starts with "*"), a day matches if either matches.
package cronexpr

import (
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"
	_ "time/tzdata" // IANA zones for schedules; the daemon image has no zoneinfo
)

// MaxLength bounds an expression (also the protocol's maxLength).
const MaxLength = 100

// Schedule is a parsed expression: one bit per allowed value of each field.
type Schedule struct {
	minute, hour, dom, month, dow uint64
	domStar, dowStar              bool
}

type field struct {
	name     string
	min, max int
}

var fields = [5]field{
	{"minute", 0, 59},
	{"hour", 0, 23},
	{"day of month", 1, 31},
	{"month", 1, 12},
	{"day of week", 0, 7},
}

// Parse parses a 5-field expression separated by single spaces.
func Parse(expr string) (Schedule, error) {
	if len(expr) > MaxLength {
		return Schedule{}, fmt.Errorf("cron expression is longer than %d bytes", MaxLength)
	}
	parts := strings.Split(expr, " ")
	if len(parts) != 5 {
		return Schedule{}, errors.New("cron expression must have 5 fields separated by single spaces (minute hour day month weekday)")
	}
	var bits [5]uint64
	for i, p := range parts {
		b, err := parseField(p, fields[i])
		if err != nil {
			return Schedule{}, err
		}
		bits[i] = b
	}
	s := Schedule{minute: bits[0], hour: bits[1], dom: bits[2], month: bits[3], dow: bits[4],
		domStar: strings.HasPrefix(parts[2], "*"), dowStar: strings.HasPrefix(parts[4], "*")}
	if s.dow&(1<<7) != 0 {
		s.dow = s.dow&^(1<<7) | 1 // 7 is Sunday
	}
	return s, nil
}

func parseField(src string, f field) (uint64, error) {
	if src == "" {
		return 0, fmt.Errorf("%s: empty field", f.name)
	}
	var bits uint64
	for item := range strings.SplitSeq(src, ",") {
		lo, hi, step := f.min, f.max, 1
		rng, stepSrc, hasStep := strings.Cut(item, "/")
		if hasStep {
			n, err := number(stepSrc, f)
			if err != nil || n < 1 || n > f.max {
				return 0, fmt.Errorf("%s: step %q must be a number from 1 to %d", f.name, stepSrc, f.max)
			}
			step = n
		}
		switch {
		case rng == "*":
		case strings.Contains(rng, "-"):
			a, b, _ := strings.Cut(rng, "-")
			var err error
			if lo, err = number(a, f); err != nil {
				return 0, err
			}
			if hi, err = number(b, f); err != nil {
				return 0, err
			}
			if lo > hi {
				return 0, fmt.Errorf("%s: range %q runs backwards", f.name, rng)
			}
		default:
			n, err := number(rng, f)
			if err != nil {
				return 0, err
			}
			lo = n
			if !hasStep {
				hi = n
			}
		}
		for v := lo; v <= hi; v += step {
			bits |= 1 << uint(v) //nolint:gosec // 0..59
		}
	}
	return bits, nil
}

func number(s string, f field) (int, error) {
	if s == "" || len(s) > 2 || strings.TrimLeft(s, "0123456789") != "" {
		return 0, fmt.Errorf("%s: %q is not a number", f.name, s)
	}
	n, _ := strconv.Atoi(s)
	if n < f.min || n > f.max {
		return 0, fmt.Errorf("%s: %d is outside %d-%d", f.name, n, f.min, f.max)
	}
	return n, nil
}

// Matches reports whether the wall clock minute of t (in t's location) is due.
func (s Schedule) Matches(t time.Time) bool {
	if s.minute&(1<<uint(t.Minute())) == 0 || s.hour&(1<<uint(t.Hour())) == 0 || s.month&(1<<uint(t.Month())) == 0 { //nolint:gosec // small
		return false
	}
	return s.dayMatches(t)
}

// WallKey is t's wall clock minute in its location, sortable as a string.
// Runs are keyed by it: a minute repeated when clocks go back runs once.
func WallKey(t time.Time) string { return t.Format("2006-01-02T15:04") }

// Next returns the first due minute after `after`, in loc, scanning at most
// five years (expressions such as "0 0 30 2 *" never match: zero time).
// Wall clock minutes that don't exist (clocks go forward) are skipped,
// repeated ones (clocks go back) are returned once.
func (s Schedule) Next(after time.Time, loc *time.Location) time.Time {
	t := after.In(loc).Truncate(time.Minute).Add(time.Minute)
	last := WallKey(after.In(loc))
	end := t.AddDate(5, 0, 0)
	for t.Before(end) {
		if s.month&(1<<uint(t.Month())) == 0 || !s.dayMatches(t) { //nolint:gosec // small
			y, m, d := t.Date()
			t = time.Date(y, m, d+1, 0, 0, 0, 0, loc)
			continue
		}
		if s.Matches(t) && WallKey(t) > last {
			return t
		}
		t = t.Add(time.Minute)
	}
	return time.Time{}
}

func (s Schedule) dayMatches(t time.Time) bool {
	dom := s.dom&(1<<uint(t.Day())) != 0     //nolint:gosec // 1..31
	dow := s.dow&(1<<uint(t.Weekday())) != 0 //nolint:gosec // 0..6
	switch {
	case s.domStar && s.dowStar:
		return true
	case s.domStar:
		return dow
	case s.dowStar:
		return dom
	default:
		return dom || dow
	}
}

// LoadLocation loads an IANA zone ("UTC" included); "Local" and "" are refused.
func LoadLocation(name string) (*time.Location, error) {
	if name == "" || name == "Local" {
		return nil, fmt.Errorf("time zone %q is not an IANA zone name", name)
	}
	loc, err := time.LoadLocation(name)
	if err != nil {
		return nil, fmt.Errorf("unknown time zone %q", name)
	}
	return loc, nil
}
