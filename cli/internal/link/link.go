// Package link reads and writes `.launchroom/project.json`, which ties a
// directory to a project, environment and optionally a service.
package link

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"

	"github.com/vantezzen/launchroom/cli/internal/config"
)

// DirName and FileName locate the link file.
const (
	DirName  = ".launchroom"
	FileName = "project.json"
)

// Link is the content of the link file.
type Link struct {
	URL           string `json:"url"`
	ProjectID     string `json:"projectId"`
	EnvironmentID string `json:"environmentId"`
	ServiceID     string `json:"serviceId,omitempty"`
}

// Find walks from dir up to the filesystem root and returns the first link
// file found and the directory holding it. (nil, "", nil) when none.
func Find(dir string) (*Link, string, error) {
	dir, err := filepath.Abs(dir)
	if err != nil {
		return nil, "", err
	}
	for {
		path := filepath.Join(dir, DirName, FileName)
		b, err := os.ReadFile(path) //nolint:gosec // the link file of the working directory or a parent
		if err == nil {
			var l Link
			if err := json.Unmarshal(b, &l); err != nil {
				return nil, "", fmt.Errorf("%s is not valid JSON: %w", path, err)
			}
			return &l, dir, nil
		}
		if !errors.Is(err, fs.ErrNotExist) {
			return nil, "", err
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return nil, "", nil
		}
		dir = parent
	}
}

// Write stores l in dir/.launchroom/project.json and returns the path. The
// file holds ids only, no secrets, so it may be committed.
func Write(dir string, l Link) (string, error) {
	b, err := json.MarshalIndent(l, "", "  ")
	if err != nil {
		return "", err
	}
	path := filepath.Join(dir, DirName, FileName)
	return path, config.WriteFileAtomic(path, append(b, '\n'), 0o644)
}
