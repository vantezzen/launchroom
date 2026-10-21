package backup

import (
	"bufio"
	"bytes"
	"compress/gzip"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Imports read the format and versions of a dump from the file itself,
// never from the dashboard (D55, docs/14 §12.3).

// sniffBytes is how much of a (decompressed) file is looked at.
const sniffBytes = 1 << 20

// Families of dump sources.
const (
	familyPostgres = "PostgreSQL"
	familyMySQL    = "MySQL"
	familyMariaDB  = "MariaDB"
	familyMongoDB  = "MongoDB"
)

// ErrUnrecognised is a file launchroom can't import.
var ErrUnrecognised = errors.New("launchroom couldn't recognise this file. Supported: pg_dump -Fc, plain SQL (.sql, .sql.gz), mysqldump or mariadb-dump SQL, mongodump --archive")

// Version is an engine version as a dump names it.
type Version struct {
	Major, Minor int
	Raw          string
}

// Known reports whether the version was found.
func (v Version) Known() bool { return v.Raw != "" }

// String is the short form ("16.4").
func (v Version) String() string { return v.Raw }

var reVersion = regexp.MustCompile(`^\s*([0-9]+)(?:\.([0-9]+))?(?:\.([0-9]+))?`)

// ParseVersion reads a leading "16.4", "8.0.36-…", "10.11.6-MariaDB".
func ParseVersion(s string) Version {
	m := reVersion.FindStringSubmatch(s)
	if m == nil {
		return Version{}
	}
	maj, _ := strconv.Atoi(m[1])
	mnr, _ := strconv.Atoi(m[2])
	raw := m[1]
	if m[2] != "" {
		raw += "." + m[2]
	}
	if m[3] != "" {
		raw += "." + m[3]
	}
	return Version{Major: maj, Minor: mnr, Raw: raw}
}

// Sniffed describes a dump file.
type Sniffed struct {
	Format state.DataFormat
	// Compressed: the whole file is gzip-compressed (launchroomd gunzips it).
	Compressed bool
	// Family is the engine that made it (PostgreSQL, MySQL, MariaDB, MongoDB; "" unknown).
	Family string
	// Server is the source server's version, Tool pg_dump's (Postgres).
	Server, Tool Version
	// InnerGzip: a mongodump archive whose collections are --gzip compressed.
	InnerGzip bool
	// Databases are the databases a mongodump archive holds.
	Databases []string
	// Generic: SQL without a dump tool's header (imported as SQL of the target engine).
	Generic bool
}

// SourceVersion is the result's "PostgreSQL 16.4".
func (s Sniffed) SourceVersion() string {
	if s.Family == "" || !s.Server.Known() {
		return ""
	}
	return s.Family + " " + s.Server.String()
}

// SniffFile looks at the start of a file.
func SniffFile(path string) (Sniffed, error) {
	f, err := os.Open(path) //nolint:gosec // generated path inside the data dir
	if err != nil {
		return Sniffed{}, err
	}
	defer func() { _ = f.Close() }()
	return Sniff(f)
}

// Sniff reads the start of a stream (gunzipping it when it is gzip).
func Sniff(r io.Reader) (Sniffed, error) {
	br := bufio.NewReaderSize(r, 64<<10)
	compressed := false
	var src io.Reader = br
	if magic, _ := br.Peek(2); len(magic) == 2 && magic[0] == 0x1f && magic[1] == 0x8b {
		zr, err := gzip.NewReader(br)
		if err != nil {
			return Sniffed{}, fmt.Errorf("the file looks gzipped but isn't readable: %w", err)
		}
		compressed, src = true, zr
	}
	head, err := io.ReadAll(io.LimitReader(src, sniffBytes))
	if err != nil && len(head) == 0 {
		return Sniffed{}, fmt.Errorf("reading the file: %w", err)
	}
	s, err := sniffHead(head)
	s.Compressed = compressed
	return s, err
}

func sniffHead(head []byte) (Sniffed, error) {
	switch {
	case bytes.HasPrefix(head, []byte("PGDMP")):
		return sniffPGCustom(head)
	case len(head) >= 4 && binary.LittleEndian.Uint32(head) == mongoArchiveMagic:
		return sniffMongoArchive(head)
	}
	text := head
	if i := bytes.IndexByte(text, 0); i >= 0 {
		return Sniffed{}, ErrUnrecognised
	}
	if !utf8.Valid(trimTornRune(text)) {
		return Sniffed{}, ErrUnrecognised
	}
	s := string(text)
	switch {
	case strings.Contains(s, "-- PostgreSQL database cluster dump"):
		return Sniffed{}, errors.New("this is a pg_dumpall file with every database and role of a server; dump one database with pg_dump -Fc instead")
	case strings.Contains(s, "-- PostgreSQL database dump") || strings.Contains(s, "-- Dumped from database version"):
		return Sniffed{
			Format: state.FormatPostgresSQL, Family: familyPostgres,
			Server: ParseVersion(lineAfter(s, "-- Dumped from database version")),
			Tool:   ParseVersion(lineAfter(s, "-- Dumped by pg_dump version")),
		}, nil
	case strings.Contains(s, "-- MySQL dump") || strings.Contains(s, "-- MariaDB dump") || strings.HasPrefix(s, mariaSandbox):
		family := familyMySQL
		server := lineAfter(s, "-- Server version")
		if strings.Contains(server, "MariaDB") || strings.Contains(s, "-- MariaDB dump") || strings.HasPrefix(s, mariaSandbox) {
			family = familyMariaDB
		}
		return Sniffed{Format: state.FormatMySQLSQL, Family: family, Server: ParseVersion(server)}, nil
	}
	if looksLikeSQL(s) {
		return Sniffed{Generic: true}, nil
	}
	return Sniffed{}, ErrUnrecognised
}

// trimTornRune drops a rune cut off at the end of the sniffed prefix.
func trimTornRune(b []byte) []byte {
	for i := 0; i < 3 && len(b) > 0; i++ {
		if utf8.Valid(b) {
			return b
		}
		b = b[:len(b)-1]
	}
	return b
}

var reSQLStart = regexp.MustCompile(`(?is)^(\s|--[^\n]*\n|/\*.*?\*/)*(SET|CREATE|INSERT|BEGIN|START|DROP|ALTER|COPY|SELECT|LOCK|USE)\b`)

func looksLikeSQL(s string) bool { return reSQLStart.MatchString(s) }

// lineAfter returns the rest of the first line starting with prefix (trimmed).
func lineAfter(s, prefix string) string {
	i := strings.Index(s, prefix)
	if i < 0 {
		return ""
	}
	rest := s[i+len(prefix):]
	if j := strings.IndexByte(rest, '\n'); j >= 0 {
		rest = rest[:j]
	}
	return strings.TrimLeft(strings.TrimSpace(rest), ":\t ")
}

// ---- pg_dump custom format (pg_backup_archiver.c ReadHead) ----

// pgHeader reads the custom format's header fields.
type pgHeader struct {
	b       []byte
	off     int
	intSize int
	version int // MAKE_ARCHIVE_VERSION
}

func pgVersion(maj, mnr, rev int) int { return (maj*256+mnr)*256 + rev }

var errShort = errors.New("short header")

func (h *pgHeader) byte() (int, error) {
	if h.off >= len(h.b) {
		return 0, errShort
	}
	v := int(h.b[h.off])
	h.off++
	return v, nil
}

func (h *pgHeader) int() (int, error) {
	sign := 0
	if h.version > pgVersion(1, 0, 0) {
		s, err := h.byte()
		if err != nil {
			return 0, err
		}
		sign = s
	}
	res := 0
	for i := range h.intSize {
		bv, err := h.byte()
		if err != nil {
			return 0, err
		}
		if i < 8 {
			res |= bv << (8 * i)
		}
	}
	if sign != 0 {
		res = -res
	}
	return res, nil
}

func (h *pgHeader) str() (string, error) {
	n, err := h.int()
	if err != nil || n < 0 {
		return "", err
	}
	if n > 4096 || h.off+n > len(h.b) {
		return "", errShort
	}
	s := string(h.b[h.off : h.off+n])
	h.off += n
	return s, nil
}

func sniffPGCustom(head []byte) (Sniffed, error) {
	bad := errors.New("this pg_dump file has a damaged header")
	h := &pgHeader{b: head, off: 5}
	vmaj, err1 := h.byte()
	vmin, err2 := h.byte()
	if err1 != nil || err2 != nil {
		return Sniffed{}, bad
	}
	vrev := 0
	if vmaj > 1 || vmaj == 1 && vmin > 0 {
		v, err := h.byte()
		if err != nil {
			return Sniffed{}, bad
		}
		vrev = v
	}
	h.version = pgVersion(vmaj, vmin, vrev)
	is, err := h.byte()
	if err != nil || is < 1 || is > 8 {
		return Sniffed{}, bad
	}
	h.intSize = is
	if h.version >= pgVersion(1, 7, 0) {
		if _, err := h.byte(); err != nil { // offSize
			return Sniffed{}, bad
		}
	}
	format, err := h.byte()
	if err != nil {
		return Sniffed{}, bad
	}
	// archCustom = 1, archTar = 3 (a tar archive never starts with PGDMP), archDirectory = 5 (toc.dat).
	if format != 1 {
		return Sniffed{}, errors.New("this pg_dump archive isn't in the custom format; dump with pg_dump -Fc or as plain SQL")
	}
	switch {
	case h.version >= pgVersion(1, 15, 0):
		_, err = h.byte()
	case h.version >= pgVersion(1, 4, 0):
		_, err = h.int()
	case h.version >= pgVersion(1, 2, 0):
		_, err = h.byte()
	}
	if err != nil {
		return Sniffed{}, bad
	}
	s := Sniffed{Format: state.FormatPostgresCustom, Family: familyPostgres}
	if h.version >= pgVersion(1, 4, 0) {
		for range 7 { // creation time
			if _, err := h.int(); err != nil {
				return Sniffed{}, bad
			}
		}
		if _, err := h.str(); err != nil { // database name
			return Sniffed{}, bad
		}
	}
	if h.version >= pgVersion(1, 10, 0) {
		remote, err1 := h.str()
		dump, err2 := h.str()
		if err1 != nil || err2 != nil {
			return Sniffed{}, bad
		}
		s.Server, s.Tool = ParseVersion(remote), ParseVersion(dump)
	}
	return s, nil
}

// ---- mongodump --archive (mongo-tools archive/prelude.go) ----

const (
	mongoArchiveMagic = 0x8199e26d
	mongoTerminator   = 0xffffffff
)

// bsonDoc reads one BSON document at off and returns its top-level string
// and boolean fields and the offset after it.
func bsonDoc(b []byte, off int) (map[string]string, int, error) {
	if off+5 > len(b) {
		return nil, 0, errShort
	}
	n := int(int32(binary.LittleEndian.Uint32(b[off:]))) //nolint:gosec // BSON lengths are int32
	if n < 5 || off+n > len(b) {
		return nil, 0, errShort
	}
	end := off + n - 1
	out := map[string]string{}
	p := off + 4
	for p < end {
		t := b[p]
		p++
		z := bytes.IndexByte(b[p:end], 0)
		if z < 0 {
			return nil, 0, errShort
		}
		name := string(b[p : p+z])
		p += z + 1
		var size int
		switch t {
		case 0x01, 0x09, 0x11, 0x12: // double, datetime, timestamp, int64
			size = 8
		case 0x10: // int32
			size = 4
		case 0x08: // bool
			size = 1
			if p < end {
				out[name] = strconv.FormatBool(b[p] != 0)
			}
		case 0x0A: // null
			size = 0
		case 0x07: // ObjectId
			size = 12
		case 0x13: // decimal128
			size = 16
		case 0x02, 0x0D, 0x0E: // string, JS code, symbol
			if p+4 > end {
				return nil, 0, errShort
			}
			l := int(int32(binary.LittleEndian.Uint32(b[p:]))) //nolint:gosec // BSON lengths are int32
			if l < 1 || p+4+l > end {
				return nil, 0, errShort
			}
			if t == 0x02 {
				out[name] = string(b[p+4 : p+4+l-1])
			}
			size = 4 + l
		case 0x03, 0x04: // document, array
			if p+4 > end {
				return nil, 0, errShort
			}
			size = int(int32(binary.LittleEndian.Uint32(b[p:]))) //nolint:gosec // BSON lengths are int32
		case 0x05: // binary
			if p+4 > end {
				return nil, 0, errShort
			}
			size = 5 + int(int32(binary.LittleEndian.Uint32(b[p:]))) //nolint:gosec // BSON lengths are int32
		default:
			return nil, 0, fmt.Errorf("bson type %#x", t)
		}
		if size < 0 || p+size > end {
			return nil, 0, errShort
		}
		p += size
	}
	return out, off + n, nil
}

func sniffMongoArchive(head []byte) (Sniffed, error) {
	bad := errors.New("this mongodump archive has a damaged header")
	hdr, off, err := bsonDoc(head, 4)
	if err != nil {
		return Sniffed{}, bad
	}
	s := Sniffed{Format: state.FormatMongoArchive, Family: familyMongoDB, Server: ParseVersion(hdr["server_version"])}
	seen := map[string]bool{}
	// Prelude: collection metadata documents until the terminator.
	for {
		if off+4 > len(head) {
			return s, nil // a very long prelude: databases unknown
		}
		if binary.LittleEndian.Uint32(head[off:]) == mongoTerminator {
			off += 4
			break
		}
		meta, next, err := bsonDoc(head, off)
		if err != nil {
			return s, nil
		}
		if db := meta["db"]; db != "" && !seen[db] {
			seen[db] = true
			s.Databases = append(s.Databases, db)
		}
		off = next
	}
	// Body: namespace header, then the collection's bytes; --gzip ones start
	// with the gzip magic (directly or after a block's length prefix).
	for range 64 {
		nh, next, err := bsonDoc(head, off)
		if err != nil {
			return s, nil
		}
		off = next
		if nh["EOF"] == "true" {
			if off+4 <= len(head) && binary.LittleEndian.Uint32(head[off:]) == mongoTerminator {
				off += 4
			}
			continue
		}
		for _, at := range []int{off, off + 4} {
			if at+2 <= len(head) && head[at] == 0x1f && head[at+1] == 0x8b {
				s.InnerGzip = true
			}
		}
		return s, nil
	}
	return s, nil
}

// ---- compatibility ----

// checkCompatible decides whether a sniffed dump can be imported into the
// target engine at targetVersion (read from the running database). target
// names the service for messages ("postgres").
func checkCompatible(s Sniffed, engine state.DatabaseEngine, targetVersion Version, target string) ([]string, error) {
	var warnings []string
	targetFamily := map[state.DatabaseEngine]string{
		state.EnginePostgres: familyPostgres, state.EngineMySQL: familyMySQL, state.EngineMariaDB: familyMariaDB, state.EngineMongoDB: familyMongoDB,
	}[engine]
	switch engine {
	case state.EnginePostgres:
		if s.Format != state.FormatPostgresCustom && s.Format != state.FormatPostgresSQL {
			return nil, fmt.Errorf("this is a %s file, but %s is PostgreSQL", describeFormat(s), target)
		}
	case state.EngineMySQL, state.EngineMariaDB:
		if s.Format != state.FormatMySQLSQL {
			return nil, fmt.Errorf("this is a %s file, but %s is %s", describeFormat(s), target, targetFamily)
		}
		if s.Family != targetFamily {
			warnings = append(warnings, fmt.Sprintf("This dump comes from %s; %s-only syntax may not import", strings.TrimSpace(s.Family+" "+s.Server.String()), s.Family))
			return warnings, nil
		}
	case state.EngineMongoDB:
		if s.Format != state.FormatMongoArchive {
			return nil, fmt.Errorf("this is a %s file, but %s is MongoDB; use mongodump --archive", describeFormat(s), target)
		}
		if dbs := userDatabases(s.Databases); len(dbs) > 1 {
			return nil, fmt.Errorf("this archive holds several databases (%s); dump one with mongodump --db=<name> --archive", strings.Join(dbs, ", "))
		}
	default:
		return nil, fmt.Errorf("imports into %s: %w", engine, ErrUnsupported)
	}
	if !targetVersion.Known() {
		return warnings, nil
	}
	newer := func(v Version) bool {
		if !v.Known() {
			return false
		}
		if engine == state.EnginePostgres && v.Major >= 10 && targetVersion.Major >= 10 {
			return v.Major > targetVersion.Major
		}
		return v.Major > targetVersion.Major || v.Major == targetVersion.Major && v.Minor > targetVersion.Minor
	}
	targetName := targetFamily + " " + majorOf(engine, targetVersion)
	switch {
	case newer(s.Server):
		return nil, fmt.Errorf("this dump comes from %s %s, but %s runs %s. Pick %s %s or newer for the database, or dump with an older version",
			s.Family, s.Server, target, targetName, s.Family, majorOf(engine, s.Server))
	case engine == state.EnginePostgres && newer(s.Tool):
		return nil, fmt.Errorf("this dump was made with pg_dump %s, but %s runs %s. Pick PostgreSQL %s or newer for the database, or dump with pg_dump %s",
			s.Tool, target, targetName, majorOf(engine, s.Tool), majorOf(engine, targetVersion))
	}
	return warnings, nil
}

// majorOf is the version as users pick it: "17" for Postgres ≥ 10, else "8.4".
func majorOf(engine state.DatabaseEngine, v Version) string {
	if engine == state.EnginePostgres && v.Major >= 10 {
		return strconv.Itoa(v.Major)
	}
	return strconv.Itoa(v.Major) + "." + strconv.Itoa(v.Minor)
}

func userDatabases(dbs []string) []string {
	var out []string
	for _, d := range dbs {
		if d != "admin" && d != "config" && d != "local" {
			out = append(out, d)
		}
	}
	return out
}

func describeFormat(s Sniffed) string {
	switch s.Format {
	case state.FormatPostgresCustom:
		return "pg_dump custom"
	case state.FormatPostgresSQL:
		return "PostgreSQL SQL"
	case state.FormatMySQLSQL:
		return strings.TrimSpace(s.Family + " SQL")
	case state.FormatMongoArchive:
		return "mongodump archive"
	}
	return "SQL"
}
