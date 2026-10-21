package backup

import (
	"bufio"
	"bytes"
	"errors"
	"fmt"
	"io"
	"regexp"
	"strings"
)

// Line filters for SQL dumps (D55, docs/14 §12.3). They stream: a line is
// inspected only while it fits the reader's buffer (64 KiB); longer lines
// (data: INSERT values, COPY rows) pass through untouched.

// mariaSandbox is the first line of MariaDB ≥ 10.5.25 dumps; MySQL's client
// stops at it ("Unknown command '\-'").
const mariaSandbox = `/*M!999999\- enable the sandbox mode */`

// filterBuffer bounds the lines the filters look at.
const filterBuffer = 64 << 10

// lineFilter calls decide for every line that fits the buffer; decide
// returns the bytes to write (nil drops the line). Longer lines are copied.
func lineFilter(dst io.Writer, src io.Reader, decide func(line []byte) ([]byte, error), long func()) error {
	br := bufio.NewReaderSize(src, filterBuffer)
	bw := bufio.NewWriterSize(dst, 256<<10)
	inLong := false
	for {
		line, err := br.ReadSlice('\n')
		switch {
		case errors.Is(err, bufio.ErrBufferFull):
			if !inLong && long != nil {
				long()
			}
			inLong = true
			if _, werr := bw.Write(line); werr != nil {
				return werr
			}
			continue
		case err != nil && !errors.Is(err, io.EOF):
			return err
		}
		if inLong {
			inLong = false
			if _, werr := bw.Write(line); werr != nil {
				return werr
			}
		} else if len(line) > 0 {
			out, derr := decide(line)
			if derr != nil {
				return derr
			}
			if out != nil {
				if _, werr := bw.Write(out); werr != nil {
					return werr
				}
			}
		}
		if errors.Is(err, io.EOF) {
			return bw.Flush()
		}
	}
}

// ---- MySQL / MariaDB ----

var (
	reDefiner  = regexp.MustCompile("(?i)DEFINER\\s*=\\s*(?:`[^`]*`|'[^']*'|\"[^\"]*\"|[^\\s@]+)\\s*@\\s*(?:`[^`]*`|'[^']*'|\"[^\"]*\"|[^\\s*]+)\\s*")
	reUseDB    = regexp.MustCompile("(?i)^USE\\s+`?([^`;\\s]+)`?\\s*;\\s*$")
	reCreateDB = regexp.MustCompile("(?i)^(?:/\\*!\\d+\\s*)?CREATE\\s+(?:DATABASE|SCHEMA)\\b(?:\\s*/\\*!\\d+\\s*IF NOT EXISTS\\s*\\*/|\\s+IF NOT EXISTS)?\\s*`?([^`;\\s]+)`?")
)

// mysqlFilter adjusts a mysqldump for a database whose user is not root:
// DEFINER clauses go (objects belong to the importing user), GTID and
// SQL_LOG_BIN statements are skipped (they need SUPER-like privileges and
// only matter to replication), MariaDB's sandbox line is skipped, and
// CREATE DATABASE / USE statements are dropped so everything lands in the
// target database (two different databases refuse the import).
type mysqlFilter struct {
	definers  int
	gtid      bool
	sandbox   bool
	databases []string
	skipping  bool
	first     bool
}

func newMySQLFilter() *mysqlFilter { return &mysqlFilter{first: true} }

func (f *mysqlFilter) run(dst io.Writer, src io.Reader) error {
	return lineFilter(dst, src, f.line, nil)
}

func (f *mysqlFilter) line(line []byte) ([]byte, error) {
	first := f.first
	f.first = false
	t := strings.TrimSpace(string(line))
	if f.skipping {
		if strings.HasSuffix(t, ";") {
			f.skipping = false
		}
		return nil, nil
	}
	if first && t == mariaSandbox {
		f.sandbox = true
		return nil, nil
	}
	up := strings.ToUpper(t)
	switch {
	case strings.HasPrefix(up, "SET @@GLOBAL.GTID_PURGED") || strings.HasPrefix(up, "SET GLOBAL GTID_SLAVE_POS") || strings.HasPrefix(up, "SET @@GLOBAL.GTID_SLAVE_POS"):
		f.gtid = true
		f.skipping = !strings.HasSuffix(t, ";")
		return nil, nil
	case strings.HasPrefix(up, "SET ") && strings.Contains(up, "SQL_LOG_BIN"):
		f.gtid = true
		return nil, nil
	}
	if m := reCreateDB.FindStringSubmatch(t); m != nil {
		if err := f.database(m[1]); err != nil {
			return nil, err
		}
		f.skipping = !strings.HasSuffix(t, ";")
		return nil, nil
	}
	if m := reUseDB.FindStringSubmatch(t); m != nil {
		return nil, f.database(m[1])
	}
	if !strings.HasPrefix(up, "INSERT ") && !strings.HasPrefix(up, "REPLACE ") && strings.Contains(up, "DEFINER") {
		out := reDefiner.ReplaceAll(line, nil)
		if !bytes.Equal(out, line) {
			f.definers++
			return out, nil
		}
	}
	return line, nil
}

func (f *mysqlFilter) database(name string) error {
	for _, d := range f.databases {
		if d == name {
			return nil
		}
	}
	f.databases = append(f.databases, name)
	if len(f.databases) > 1 {
		return fmt.Errorf("this dump holds more than one database (%s); dump one with mysqldump <database>", strings.Join(f.databases, ", "))
	}
	return nil
}

// warnings describes what the filter changed.
func (f *mysqlFilter) warnings(target string) []string {
	var w []string
	if f.definers > 0 {
		w = append(w, fmt.Sprintf("Removed DEFINER from %d views, routines or triggers; they belong to the database's own user now", f.definers))
	}
	if f.gtid {
		w = append(w, "Skipped GTID and binary log settings (they only matter to replication)")
	}
	if len(f.databases) == 1 && f.databases[0] != target {
		w = append(w, fmt.Sprintf("Imported database %s into %s", f.databases[0], target))
	}
	return w
}

// ---- PostgreSQL plain SQL ----

var (
	reOwnerTo = regexp.MustCompile(`(?i)^ALTER\s+.+\s+OWNER\s+TO\s+.+;\s*$`)
	reGrant   = regexp.MustCompile(`(?i)^(GRANT|REVOKE)\s.+\s(TO|FROM)\s.+;\s*$`)
	reDefPriv = regexp.MustCompile(`(?i)^ALTER\s+DEFAULT\s+PRIVILEGES\s.+;\s*$`)
)

// pgFilter leaves out ownership and privileges of a plain pg_dump (roles of
// the old server don't exist here): ALTER … OWNER TO, GRANT, REVOKE and
// ALTER DEFAULT PRIVILEGES lines outside COPY data.
type pgFilter struct {
	inCopy  bool
	dropped int
}

func (f *pgFilter) run(dst io.Writer, src io.Reader) error {
	return lineFilter(dst, src, f.line, nil)
}

func (f *pgFilter) line(line []byte) ([]byte, error) {
	t := strings.TrimRight(string(line), "\r\n")
	if f.inCopy {
		if t == `\.` {
			f.inCopy = false
		}
		return line, nil
	}
	up := strings.ToUpper(t)
	if strings.HasPrefix(up, "COPY ") && strings.HasSuffix(up, "FROM STDIN;") {
		f.inCopy = true
		return line, nil
	}
	if reOwnerTo.MatchString(t) || reGrant.MatchString(t) || reDefPriv.MatchString(t) {
		f.dropped++
		return nil, nil
	}
	return line, nil
}

// ownershipWarning is the result warning when ownership was left out.
const ownershipWarning = "Ownership and privileges were left out: everything belongs to the database's own user"
