package backup

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"compress/gzip"
	"encoding/binary"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// ---- pg_dump custom header ----

// pgCustomHeader builds the start of a pg_dump -Fc file (archive format
// vmaj.vmin.vrev, intSize 4) like pg_backup_archiver.c WriteHead.
func pgCustomHeader(vmin int, remote, dump string) []byte {
	var b bytes.Buffer
	b.WriteString("PGDMP")
	b.Write([]byte{1, byte(vmin), 0, 4, 8, 1}) //nolint:gosec // test values; vmaj vmin vrev intSize offSize format(custom)
	writeInt := func(v int) {
		sign := byte(0)
		if v < 0 {
			sign, v = 1, -v
		}
		b.WriteByte(sign)
		var x [4]byte
		binary.LittleEndian.PutUint32(x[:], uint32(v)) //nolint:gosec // test values
		b.Write(x[:])
	}
	writeStr := func(s string) {
		writeInt(len(s))
		b.WriteString(s)
	}
	if vmin >= 15 {
		b.WriteByte(0) // compression algorithm none
	} else {
		writeInt(-1)
	}
	for _, v := range []int{12, 30, 9, 21, 9, 126, 0} {
		writeInt(v)
	}
	writeStr("app")
	writeStr(remote)
	writeStr(dump)
	b.Write(bytes.Repeat([]byte{0xAB}, 64)) // TOC follows
	return b.Bytes()
}

// bsonDocOf encodes a flat BSON document of strings, int32s and bools.
func bsonDocOf(fields ...any) []byte {
	var body bytes.Buffer
	for i := 0; i+1 < len(fields); i += 2 {
		name := fields[i].(string) //nolint:forcetypeassert // test helper
		switch v := fields[i+1].(type) {
		case string:
			body.WriteByte(0x02)
			body.WriteString(name)
			body.WriteByte(0)
			var l [4]byte
			binary.LittleEndian.PutUint32(l[:], uint32(len(v)+1)) //nolint:gosec // test values
			body.Write(l[:])
			body.WriteString(v)
			body.WriteByte(0)
		case int:
			body.WriteByte(0x10)
			body.WriteString(name)
			body.WriteByte(0)
			var x [4]byte
			binary.LittleEndian.PutUint32(x[:], uint32(v)) //nolint:gosec // test values
			body.Write(x[:])
		case bool:
			body.WriteByte(0x08)
			body.WriteString(name)
			body.WriteByte(0)
			if v {
				body.WriteByte(1)
			} else {
				body.WriteByte(0)
			}
		}
	}
	out := make([]byte, 4, 5+body.Len())
	binary.LittleEndian.PutUint32(out, uint32(5+body.Len())) //nolint:gosec // test values
	out = append(out, body.Bytes()...)
	return append(out, 0)
}

func mongoArchive(gzipped bool, dbs ...string) []byte {
	var b bytes.Buffer
	var m [4]byte
	binary.LittleEndian.PutUint32(m[:], mongoArchiveMagic)
	b.Write(m[:])
	b.Write(bsonDocOf("concurrent_collections", 4, "version", "0.1", "server_version", "7.0.5", "tool_version", "100.9.4"))
	for _, db := range dbs {
		b.Write(bsonDocOf("db", db, "collection", "notes", "metadata", "{}", "size", 0))
	}
	b.Write([]byte{0xff, 0xff, 0xff, 0xff})
	b.Write(bsonDocOf("db", dbs[0], "collection", "notes", "EOF", false))
	if gzipped {
		b.Write([]byte{0x1f, 0x8b, 0x08, 0x00})
	} else {
		b.Write(bsonDocOf("n", 1))
	}
	return b.Bytes()
}

func gz(t *testing.T, b []byte) []byte {
	t.Helper()
	var out bytes.Buffer
	zw := gzip.NewWriter(&out)
	_, _ = zw.Write(b)
	_ = zw.Close()
	return out.Bytes()
}

func TestSniff(t *testing.T) {
	cases := map[string]struct {
		in         []byte
		format     state.DataFormat
		family     string
		server     string
		tool       string
		compressed bool
		innerGzip  bool
	}{
		"pg custom 1.15": {in: pgCustomHeader(15, "16.4 (Debian 16.4-1.pgdg120+1)", "16.4"), format: state.FormatPostgresCustom, family: familyPostgres, server: "16.4", tool: "16.4"},
		"pg custom 1.14": {in: pgCustomHeader(14, "13.11", "13.11"), format: state.FormatPostgresCustom, family: familyPostgres, server: "13.11", tool: "13.11"},
		"pg sql gz": {in: gz(t, []byte("--\n-- PostgreSQL database dump\n--\n\n-- Dumped from database version 15.8\n-- Dumped by pg_dump version 15.8\n\nSET statement_timeout = 0;\n")),
			format: state.FormatPostgresSQL, family: familyPostgres, server: "15.8", tool: "15.8", compressed: true},
		"mysqldump": {in: []byte("-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n--\n-- Host: localhost    Database: app\n-- ------------------------------------------------------\n-- Server version\t8.0.36\n"),
			format: state.FormatMySQLSQL, family: familyMySQL, server: "8.0.36"},
		"mariadb sandbox": {in: []byte(mariaSandbox + "\n-- MariaDB dump 10.19-11.4.2-MariaDB, for debian-linux-gnu (x86_64)\n--\n-- Server version\t11.4.2-MariaDB-ubu2404\n"),
			format: state.FormatMySQLSQL, family: familyMariaDB, server: "11.4.2"},
		"mongo archive gzip collections": {in: mongoArchive(true, "app"), format: state.FormatMongoArchive, family: familyMongoDB, server: "7.0.5", innerGzip: true},
		"mongo archive gzipped whole":    {in: gz(t, mongoArchive(false, "app")), format: state.FormatMongoArchive, family: familyMongoDB, server: "7.0.5", compressed: true},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			s, err := Sniff(bytes.NewReader(c.in))
			if err != nil {
				t.Fatal(err)
			}
			if s.Format != c.format || s.Family != c.family || s.Server.String() != c.server || s.Tool.String() != c.tool || s.Compressed != c.compressed || s.InnerGzip != c.innerGzip {
				t.Fatalf("sniffed %+v", s)
			}
		})
	}
	if _, err := Sniff(strings.NewReader("--\n-- PostgreSQL database cluster dump\n--\n")); err == nil || !strings.Contains(err.Error(), "pg_dumpall") {
		t.Fatalf("pg_dumpall: %v", err)
	}
	if _, err := Sniff(bytes.NewReader([]byte{0x89, 'P', 'N', 'G', 0, 0})); !errors.Is(err, ErrUnrecognised) {
		t.Fatalf("png: %v", err)
	}
	if s, err := Sniff(strings.NewReader("CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n")); err != nil || !s.Generic {
		t.Fatalf("generic sql: %+v %v", s, err)
	}
	s, err := Sniff(bytes.NewReader(mongoArchive(false, "app", "other", "admin")))
	if err != nil || len(s.Databases) != 3 {
		t.Fatalf("databases %+v %v", s, err)
	}
}

func TestCheckCompatible(t *testing.T) {
	pg := func(server, tool string) Sniffed {
		return Sniffed{Format: state.FormatPostgresCustom, Family: familyPostgres, Server: ParseVersion(server), Tool: ParseVersion(tool)}
	}
	if _, err := checkCompatible(pg("15.8", "15.8"), state.EnginePostgres, ParseVersion("17.6"), "db"); err != nil {
		t.Fatalf("older dump refused: %v", err)
	}
	if _, err := checkCompatible(pg("9.6.24", "9.6.24"), state.EnginePostgres, ParseVersion("16.4"), "db"); err != nil {
		t.Fatalf("9.6 dump refused: %v", err)
	}
	_, err := checkCompatible(pg("18.1", "18.1"), state.EnginePostgres, ParseVersion("17.6"), "db")
	if err == nil || !strings.Contains(err.Error(), "PostgreSQL 18.1") || !strings.Contains(err.Error(), "PostgreSQL 17") {
		t.Fatalf("newer dump: %v", err)
	}
	if _, err := checkCompatible(pg("16.4", "17.2"), state.EnginePostgres, ParseVersion("16.4"), "db"); err == nil || !strings.Contains(err.Error(), "pg_dump 17.2") {
		t.Fatalf("newer pg_dump: %v", err)
	}
	my := Sniffed{Format: state.FormatMySQLSQL, Family: familyMySQL, Server: ParseVersion("9.1.0")}
	if _, err := checkCompatible(my, state.EngineMySQL, ParseVersion("8.4.3"), "db"); err == nil || !strings.Contains(err.Error(), "MySQL 9.1.0") {
		t.Fatalf("mysql newer: %v", err)
	}
	my.Server = ParseVersion("8.0.36")
	if _, err := checkCompatible(my, state.EngineMySQL, ParseVersion("8.4.3"), "db"); err != nil {
		t.Fatal(err)
	}
	maria := Sniffed{Format: state.FormatMySQLSQL, Family: familyMariaDB, Server: ParseVersion("11.4.2")}
	if w, err := checkCompatible(maria, state.EngineMySQL, ParseVersion("8.4.3"), "db"); err != nil || len(w) != 1 {
		t.Fatalf("mariadb into mysql: %v %v", w, err)
	}
	if _, err := checkCompatible(my, state.EnginePostgres, ParseVersion("17"), "db"); err == nil {
		t.Fatal("mysql dump into postgres passed")
	}
	mongo := Sniffed{Format: state.FormatMongoArchive, Family: familyMongoDB, Server: ParseVersion("8.0.3"), Databases: []string{"app"}}
	if _, err := checkCompatible(mongo, state.EngineMongoDB, ParseVersion("7.0.5"), "db"); err == nil || !strings.Contains(err.Error(), "MongoDB 8.0.3") {
		t.Fatalf("mongo newer: %v", err)
	}
	mongo.Server, mongo.Databases = ParseVersion("6.0.1"), []string{"a", "b", "admin"}
	if _, err := checkCompatible(mongo, state.EngineMongoDB, ParseVersion("7.0.5"), "db"); err == nil || !strings.Contains(err.Error(), "several databases") {
		t.Fatalf("mongo multi db: %v", err)
	}
}

// ---- filters ----

func runFilter(t *testing.T, run func(io.Writer, io.Reader) error, in string) (string, error) {
	t.Helper()
	var out bytes.Buffer
	err := run(&out, strings.NewReader(in))
	return out.String(), err
}

func TestMySQLFilter(t *testing.T) {
	in := mariaSandbox + "\n" +
		"SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;\n" +
		"SET @@SESSION.SQL_LOG_BIN= 0;\n" +
		"SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5,\n" +
		"5A1B2C3D-71CA-11E1-9E33-C80AA9429562:1-3';\n" +
		"CREATE DATABASE /*!32312 IF NOT EXISTS*/ `old` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;\n" +
		"USE `old`;\n" +
		"CREATE TABLE `t` (`id` int);\n" +
		"INSERT INTO `t` VALUES (1),(2),('DEFINER=`root`@`%` stays in data');\n" +
		"/*!50001 CREATE ALGORITHM=UNDEFINED */\n" +
		"/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */\n" +
		"/*!50001 VIEW `v` AS select 1 */;\n" +
		"CREATE DEFINER=`admin`@`localhost` PROCEDURE `p`() SELECT 1;\n" +
		"/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER tr BEFORE INSERT ON t FOR EACH ROW SET NEW.id = NEW.id */;;\n" +
		"SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;\n"
	f := newMySQLFilter()
	out, err := runFilter(t, f.run, in)
	if err != nil {
		t.Fatal(err)
	}
	for _, gone := range []string{"sandbox", "SQL_LOG_BIN", "GTID_PURGED", "5A1B2C3D", "CREATE DATABASE", "USE `old`", "DEFINER=`admin`", "/*!50013 DEFINER", "/*!50017 DEFINER"} {
		if strings.Contains(out, gone) {
			t.Errorf("%q survived:\n%s", gone, out)
		}
	}
	for _, kept := range []string{"CREATE TABLE `t`", "'DEFINER=`root`@`%` stays in data'", "SQL SECURITY DEFINER", "CREATE PROCEDURE `p`", "TRIGGER tr"} {
		if !strings.Contains(out, kept) {
			t.Errorf("%q lost:\n%s", kept, out)
		}
	}
	w := f.warnings("app")
	if f.definers != 3 || !f.gtid || len(w) != 3 {
		t.Fatalf("definers %d gtid %v warnings %v", f.definers, f.gtid, w)
	}
	// Two databases refuse the import.
	if _, err := runFilter(t, newMySQLFilter().run, "USE `a`;\nCREATE TABLE x (i int);\nUSE `b`;\n"); err == nil || !strings.Contains(err.Error(), "more than one database") {
		t.Fatalf("two databases: %v", err)
	}
	// Lines longer than the buffer pass through untouched.
	long := "INSERT INTO t VALUES " + strings.Repeat("(1),", 40000) + "(2);\n"
	if out, err := runFilter(t, newMySQLFilter().run, long); err != nil || out != long {
		t.Fatalf("long line changed (%v)", err)
	}
}

func TestPGFilter(t *testing.T) {
	in := "SET client_encoding = 'UTF8';\n" +
		"CREATE TABLE public.events (id integer, note text);\n" +
		"ALTER TABLE public.events OWNER TO umami;\n" +
		"COPY public.events (id, note) FROM stdin;\n" +
		"1\tGRANT ALL ON x TO y;\n" +
		"2\tALTER TABLE z OWNER TO q;\n" +
		"\\.\n" +
		"GRANT ALL ON SCHEMA public TO umami;\n" +
		"REVOKE ALL ON TABLE public.events FROM PUBLIC;\n" +
		"ALTER DEFAULT PRIVILEGES FOR ROLE umami IN SCHEMA public GRANT ALL ON TABLES TO umami;\n" +
		"CREATE INDEX i ON public.events (id);\n"
	f := &pgFilter{}
	out, err := runFilter(t, f.run, in)
	if err != nil {
		t.Fatal(err)
	}
	want := "SET client_encoding = 'UTF8';\n" +
		"CREATE TABLE public.events (id integer, note text);\n" +
		"COPY public.events (id, note) FROM stdin;\n" +
		"1\tGRANT ALL ON x TO y;\n" +
		"2\tALTER TABLE z OWNER TO q;\n" +
		"\\.\n" +
		"CREATE INDEX i ON public.events (id);\n"
	if out != want || f.dropped != 4 {
		t.Fatalf("dropped %d:\n%s", f.dropped, out)
	}
}

func TestParseSourceURL(t *testing.T) {
	c, err := parseSourceURL(state.EnginePostgres, "postgres://umami:s3cr%40t@db.example.com/umami?sslmode=require")
	if err != nil {
		t.Fatal(err)
	}
	if c.pw != "s3cr@t" || c.port != "5432" || c.db != "umami" || c.display() != "postgres://db.example.com:5432/umami" {
		t.Fatalf("%+v", c)
	}
	env := strings.Join(c.pgEnv(), " ")
	if !strings.Contains(env, "PGSSLMODE=require") || !strings.Contains(env, "PGPASSWORD=s3cr@t") {
		t.Fatal(env)
	}
	for _, bad := range []string{"mysql://u:p@h/db", "postgres://h", "postgres://u:p@/db", "postgres://h:99999/db", "not a url"} {
		if _, err := parseSourceURL(state.EnginePostgres, bad); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
	m, err := parseSourceURL(state.EngineMongoDB, "mongodb+srv://app:pw@cluster0.example.net/app?retryWrites=true")
	if err != nil {
		t.Fatal(err)
	}
	cmd := m.dumpCommand(state.EngineMongoDB)
	if strings.Contains(strings.Join(cmd.argv, " "), "pw") || string(cmd.stdin) != "pw\n" {
		t.Fatalf("password in argv: %v", cmd.argv)
	}
	my, _ := parseSourceURL(state.EngineMySQL, "mysql://root:pw@203.0.113.7:3307/shop")
	d := my.dumpCommand(state.EngineMySQL)
	if strings.Contains(strings.Join(d.argv, " "), "pw") || d.env[0] != "MYSQL_PWD=pw" || d.argv[len(d.argv)-1] != "shop" {
		t.Fatalf("%v %v", d.argv, d.env)
	}
}

// ---- archives ----

type aentry struct {
	name, link string
	typ        byte
	mode       int64
	uid        int
	body       string
}

func writeTar(t *testing.T, gzipped bool, entries ...aentry) string {
	t.Helper()
	var buf bytes.Buffer
	var w io.Writer = &buf
	var zw *gzip.Writer
	if gzipped {
		zw = gzip.NewWriter(&buf)
		w = zw
	}
	tw := tar.NewWriter(w)
	for _, e := range entries {
		h := &tar.Header{Name: e.name, Linkname: e.link, Typeflag: e.typ, Mode: e.mode, Uid: e.uid, Gid: e.uid, Uname: "someone"}
		if h.Mode == 0 {
			h.Mode = 0o644
		}
		if e.typ == tar.TypeReg {
			h.Size = int64(len(e.body))
		}
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		if e.typ == tar.TypeReg {
			_, _ = tw.Write([]byte(e.body))
		}
	}
	_ = tw.Close()
	if zw != nil {
		_ = zw.Close()
	}
	p := filepath.Join(t.TempDir(), "a.tar")
	if err := os.WriteFile(p, buf.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
	return p
}

func converted(t *testing.T, p string, o archiveOptions) (map[string]*tar.Header, map[string]string, archiveStats, error) {
	t.Helper()
	var out bytes.Buffer
	stats, err := convertArchive(&out, p, o)
	if err != nil {
		return nil, nil, stats, err
	}
	hs, bodies := map[string]*tar.Header{}, map[string]string{}
	tr := tar.NewReader(&out)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		hs[h.Name] = h
		b, _ := io.ReadAll(tr)
		bodies[h.Name] = string(b)
	}
	return hs, bodies, stats, nil
}

func TestConvertArchive(t *testing.T) {
	p := writeTar(t, true,
		aentry{name: "./", typ: tar.TypeDir, mode: 0o700},
		aentry{name: "uploads/", typ: tar.TypeDir, mode: 0o750, uid: 1000},
		aentry{name: "uploads/a.png", typ: tar.TypeReg, mode: 0o4755, uid: 1000, body: "png"},
		aentry{name: "uploads/latest", typ: tar.TypeSymlink, link: "a.png", uid: 1000},
		aentry{name: "uploads/up", typ: tar.TypeSymlink, link: "../uploads", uid: 1000},
		aentry{name: "uploads/copy.png", typ: tar.TypeLink, link: "uploads/a.png", uid: 1000},
		aentry{name: "deep/er/file", typ: tar.TypeReg, body: "x", uid: 33},
		aentry{name: "pipe", typ: tar.TypeFifo},
	)
	hs, bodies, stats, err := converted(t, p, archiveOptions{DefaultOwner: state.FileOwner{UID: 1000, GID: 1000}, RootMode: 0o775, MaxBytes: 1 << 20})
	if err != nil {
		t.Fatal(err)
	}
	if stats.Format != state.FormatTar || !stats.Compressed || stats.Files != 6 || stats.Skipped != 1 {
		t.Fatalf("stats %+v", stats)
	}
	root := hs["data/"]
	if root == nil || root.Uid != 1000 || root.Mode != 0o775 {
		t.Fatalf("root %+v", root)
	}
	if h := hs["data/uploads/a.png"]; h == nil || h.Mode != 0o755 || h.Uid != 1000 || h.Uname != "" || bodies["data/uploads/a.png"] != "png" {
		t.Fatalf("file %+v", h)
	}
	if h := hs["data/deep/"]; h == nil || h.Uid != 1000 {
		t.Fatalf("implicit parent %+v", h)
	}
	if h := hs["data/deep/er/file"]; h == nil || h.Uid != 33 {
		t.Fatalf("owner not kept %+v", h)
	}
	if h := hs["data/uploads/copy.png"]; h == nil || h.Linkname != "data/uploads/a.png" {
		t.Fatalf("hard link %+v", h)
	}

	// Owner override and strip components.
	p2 := writeTar(t, false, aentry{name: "site/", typ: tar.TypeDir}, aentry{name: "site/index.html", typ: tar.TypeReg, body: "hi", uid: 0})
	hs, _, _, err = converted(t, p2, archiveOptions{Strip: 1, Owner: &state.FileOwner{UID: 101, GID: 101}, MaxBytes: 1 << 20})
	if err != nil {
		t.Fatal(err)
	}
	if h := hs["data/index.html"]; h == nil || h.Uid != 101 || hs["data/"].Uid != 101 {
		t.Fatalf("strip/owner %+v", hs)
	}
}

func TestConvertArchiveRefusals(t *testing.T) {
	cases := map[string][]aentry{
		"traversal":            {{name: "../etc/passwd", typ: tar.TypeReg, body: "x"}},
		"nested traversal":     {{name: "a/../../x", typ: tar.TypeReg, body: "x"}},
		"absolute":             {{name: "/etc/passwd", typ: tar.TypeReg, body: "x"}},
		"absolute symlink":     {{name: "l", typ: tar.TypeSymlink, link: "/etc"}},
		"escaping symlink":     {{name: "a/l", typ: tar.TypeSymlink, link: "../../etc"}},
		"through a symlink":    {{name: "l", typ: tar.TypeSymlink, link: "sub"}, {name: "l/x", typ: tar.TypeReg, body: "x"}},
		"chained escape":       {{name: "a", typ: tar.TypeSymlink, link: "b/.."}, {name: "b", typ: tar.TypeSymlink, link: "."}},
		"device node":          {{name: "sda", typ: tar.TypeBlock}},
		"char device":          {{name: "null", typ: tar.TypeChar}},
		"hard link outside":    {{name: "h", typ: tar.TypeLink, link: "../etc/shadow"}},
		"hard link to nothing": {{name: "h", typ: tar.TypeLink, link: "later"}, {name: "later", typ: tar.TypeReg, body: "x"}},
	}
	for name, entries := range cases {
		t.Run(name, func(t *testing.T) {
			p := writeTar(t, false, entries...)
			if _, _, _, err := converted(t, p, archiveOptions{MaxBytes: 1 << 20}); err == nil || !errors.Is(err, errUnsafeImport) {
				t.Fatalf("passed: %v", err)
			}
		})
	}
	// Archive bombs.
	p := writeTar(t, false, aentry{name: "big", typ: tar.TypeReg, body: strings.Repeat("x", 2048)})
	if _, _, _, err := converted(t, p, archiveOptions{MaxBytes: 1024}); err == nil || !strings.Contains(err.Error(), "expands") {
		t.Fatalf("bomb: %v", err)
	}
	// Not an archive.
	bad := filepath.Join(t.TempDir(), "x")
	_ = os.WriteFile(bad, []byte("hello"), 0o600)
	if _, _, _, err := converted(t, bad, archiveOptions{}); err == nil {
		t.Fatal("text accepted")
	}
}

func TestConvertZip(t *testing.T) {
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	add := func(name string, mode os.FileMode, body string) {
		h := &zip.FileHeader{Name: name, Method: zip.Deflate}
		h.SetMode(mode)
		w, err := zw.CreateHeader(h)
		if err != nil {
			t.Fatal(err)
		}
		_, _ = w.Write([]byte(body))
	}
	add("app/", os.ModeDir|0o755, "")
	add("app/run.sh", 0o755, "#!")
	add("app/cur", os.ModeSymlink|0o777, "run.sh")
	_ = zw.Close()
	p := filepath.Join(t.TempDir(), "a.zip")
	_ = os.WriteFile(p, buf.Bytes(), 0o600)
	hs, bodies, stats, err := converted(t, p, archiveOptions{DefaultOwner: state.FileOwner{UID: 1000, GID: 1000}, MaxBytes: 1 << 20})
	if err != nil {
		t.Fatal(err)
	}
	if stats.Format != state.FormatZip || hs["data/app/run.sh"].Mode != 0o755 || hs["data/app/run.sh"].Uid != 1000 || bodies["data/app/run.sh"] != "#!" || hs["data/app/cur"].Linkname != "run.sh" {
		t.Fatalf("%+v %+v", stats, hs)
	}

	// Zip slip.
	buf.Reset()
	zw = zip.NewWriter(&buf)
	w, _ := zw.Create("..\\..\\evil.sh")
	_, _ = w.Write([]byte("x"))
	_ = zw.Close()
	_ = os.WriteFile(p, buf.Bytes(), 0o600)
	if _, _, _, err := converted(t, p, archiveOptions{MaxBytes: 1 << 20}); !errors.Is(err, errUnsafeImport) {
		t.Fatalf("zip slip: %v", err)
	}
}

func TestRerootVolumeTar(t *testing.T) {
	var src bytes.Buffer
	tw := tar.NewWriter(&src)
	for _, h := range []*tar.Header{
		{Name: "data/", Typeflag: tar.TypeDir, Mode: 0o755, Uid: 1000},
		{Name: "data/a", Typeflag: tar.TypeReg, Mode: 0o600, Uid: 1000, Size: 1},
		{Name: "data/b", Typeflag: tar.TypeLink, Linkname: "data/a"},
		{Name: "data/s", Typeflag: tar.TypeSymlink, Linkname: "a"},
	} {
		_ = tw.WriteHeader(h)
		if h.Size > 0 {
			_, _ = tw.Write([]byte("x"))
		}
	}
	_ = tw.Close()
	var out bytes.Buffer
	if err := rerootVolumeTar(&out, &src); err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(&out)
	var names []string
	for {
		h, err := tr.Next()
		if err != nil {
			break
		}
		names = append(names, h.Name+">"+h.Linkname)
	}
	if strings.Join(names, ",") != "./>,a>,b>a,s>a" {
		t.Fatalf("%v", names)
	}
}
