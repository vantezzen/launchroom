package state

import (
	"fmt"
	"path"
	"slices"
	"strings"
	"unicode/utf8"
)

// Limits of WorkloadSpec.files (daemon feature "configFiles", docs/03 §8).
const (
	MaxConfigFiles        = 32
	MaxConfigFileBytes    = 64 << 10
	MaxConfigFilesTotal   = 256 << 10
	maxConfigFilePathSize = 255
)

// configFileForbidden are exact paths Docker manages inside every container.
var configFileForbidden = []string{"/etc/hosts", "/etc/hostname", "/etc/resolv.conf"}

// ConfigFilePath is the absolute, clean path of a config file in a container.
type ConfigFilePath string

// ProtocolSchema implements SchemaDescriber.
func (ConfigFilePath) ProtocolSchema() ScalarSchema {
	return strSchema(reContainerPath, maxConfigFilePathSize)
}

// Validate checks the grammar and the forbidden locations.
func (v ConfigFilePath) Validate() error {
	s := string(v)
	if err := checkPattern("config file path", s, reContainerPath, maxConfigFilePathSize); err != nil {
		return err
	}
	if path.Clean(s) != s || hasDotSegment(s) {
		return fmt.Errorf("config file path %q must be clean (no . or .. segments)", s)
	}
	for _, f := range forbiddenContainerPaths {
		if s == f || strings.HasPrefix(s, f+"/") {
			return fmt.Errorf("config file path %q is not allowed (inside %s)", s, f)
		}
	}
	if slices.Contains(configFileForbidden, s) {
		return fmt.Errorf("config file path %q is managed by Docker", s)
	}
	return nil
}

// ConfigFile is a literal text file launchroomd writes into the workload's
// container after creating it and before starting it (regular file, mode
// 0444, owner 0:0). Content is UTF-8 without NUL bytes.
type ConfigFile struct {
	Path    ConfigFilePath `json:"path"`
	Content string         `json:"content"`
}

func inside(p, dir string) bool {
	return p == dir || strings.HasPrefix(p, strings.TrimSuffix(dir, "/")+"/")
}

// CheckConfigFiles validates config files against the limits and the
// workload's mounts (files never write into volumes). Errors name paths,
// never content.
func CheckConfigFiles(files []ConfigFile, mounts []Mount) error {
	var e errs
	checkConfigFiles(&e, "files", files, mounts)
	return e.err()
}

func checkConfigFiles(e *errs, p string, files []ConfigFile, mounts []Mount) {
	checkCount(e, p, len(files), 0, MaxConfigFiles)
	total := 0
	seen := map[ConfigFilePath]bool{}
	for i, f := range files {
		fp := idx(p, i)
		e.check(fp+".path", f.Path)
		if seen[f.Path] {
			e.addf(fp+".path", "%q is listed twice", truncate(string(f.Path)))
		}
		seen[f.Path] = true
		for _, m := range mounts {
			if inside(string(f.Path), string(m.Target)) {
				e.addf(fp+".path", "%q is inside the mount at %s (files never write into volumes)", truncate(string(f.Path)), m.Target)
			}
		}
		for _, o := range files {
			if o.Path != f.Path && strings.HasPrefix(string(o.Path), string(f.Path)+"/") {
				e.addf(fp+".path", "%q is a directory of another file", truncate(string(f.Path)))
				break
			}
		}
		n := len(f.Content)
		total += n
		if n > MaxConfigFileBytes {
			e.addf(fp+".content", "is longer than %d bytes", MaxConfigFileBytes)
		}
		if !utf8.ValidString(f.Content) {
			e.addf(fp+".content", "is not valid UTF-8")
		}
		if strings.ContainsRune(f.Content, 0) {
			e.addf(fp+".content", "contains a NUL byte")
		}
	}
	if total > MaxConfigFilesTotal {
		e.addf(p, "content is %d bytes in total, at most %d allowed", total, MaxConfigFilesTotal)
	}
}
