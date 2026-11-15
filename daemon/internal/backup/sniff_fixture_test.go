package backup

import (
	"bytes"
	"compress/gzip"
	"encoding/base64"
	"encoding/json"
	"os"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// sniffVectors is protocol/fixtures/sniff/databases.json; the dashboard's
// FileSniffer test reads it too.
type sniffVectors struct {
	Samples []struct {
		Name   string `json:"name"`
		Text   string `json:"text"`
		Base64 string `json:"base64"`
		Gzip   bool   `json:"gzip"`
		Expect struct {
			Format        string `json:"format"`
			Family        string `json:"family"`
			ServerVersion string `json:"serverVersion"`
			ToolVersion   string `json:"toolVersion"`
			Compressed    bool   `json:"compressed"`
			Refused       bool   `json:"refused"`
			Generic       bool   `json:"generic"`
		} `json:"expect"`
	} `json:"samples"`
	Compatibility []struct {
		Sample        string `json:"sample"`
		Engine        string `json:"engine"`
		TargetVersion string `json:"targetVersion"`
		Importable    bool   `json:"importable"`
	} `json:"compatibility"`
}

var familyIDs = map[string]string{familyPostgres: "postgres", familyMySQL: "mysql", familyMariaDB: "mariadb", familyMongoDB: "mongodb"}

func TestSharedSniffVectors(t *testing.T) {
	data, err := os.ReadFile("../../../protocol/fixtures/sniff/databases.json")
	if err != nil {
		t.Fatal(err)
	}
	var v sniffVectors
	if err := json.Unmarshal(data, &v); err != nil {
		t.Fatal(err)
	}
	sniffed := map[string]Sniffed{}
	refused := map[string]bool{}
	for _, s := range v.Samples {
		raw := []byte(s.Text)
		if s.Base64 != "" {
			if raw, err = base64.StdEncoding.DecodeString(s.Base64); err != nil {
				t.Fatal(err)
			}
		}
		if s.Gzip {
			var buf bytes.Buffer
			zw := gzip.NewWriter(&buf)
			_, _ = zw.Write(raw)
			_ = zw.Close()
			raw = buf.Bytes()
		}
		got, err := Sniff(bytes.NewReader(raw))
		e := s.Expect
		switch {
		case e.Refused:
			if err == nil {
				t.Errorf("%s: sniffed %+v, want refused", s.Name, got)
			}
			refused[s.Name] = true
			continue
		case err != nil:
			t.Errorf("%s: %v", s.Name, err)
			continue
		case e.Generic:
			if !got.Generic {
				t.Errorf("%s: %+v, want generic SQL", s.Name, got)
			}
			continue
		}
		if string(got.Format) != e.Format || familyIDs[got.Family] != e.Family || got.Server.String() != e.ServerVersion ||
			got.Tool.String() != e.ToolVersion || got.Compressed != e.Compressed {
			t.Errorf("%s: sniffed %+v (%s %s %s), want %+v", s.Name, got, got.Family, got.Server, got.Tool, e)
		}
		sniffed[s.Name] = got
	}
	for _, c := range v.Compatibility {
		importable := false
		if s, ok := sniffed[c.Sample]; ok && !refused[c.Sample] {
			_, err := checkCompatible(s, state.DatabaseEngine(c.Engine), ParseVersion(c.TargetVersion), "db")
			importable = err == nil
		}
		if importable != c.Importable {
			t.Errorf("%s into %s %s: importable %v, want %v", c.Sample, c.Engine, c.TargetVersion, importable, c.Importable)
		}
	}
}
