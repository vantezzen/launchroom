package presets

import (
	"encoding/json"
	"io/fs"
	"path"
	"regexp"
	"strings"
)

// PHPFacts are facts about a PHP app (Laravel, Symfony, plain PHP).
type PHPFacts struct {
	// ComposerVersions maps composer.json require/require-dev names to their constraints.
	ComposerVersions map[string]string
	// Laravel facts (zero unless artisan exists).
	Laravel LaravelFacts
	// Symfony is set for a Symfony app (framework-bundle, or bin/console with symfony.lock).
	Symfony bool
	// PublicIndex is set when public/index.php exists and the root has no index.php.
	PublicIndex bool
}

// LaravelFacts are read from a Laravel app's bootstrap and config files.
type LaravelFacts struct {
	// HealthPath is the health route from bootstrap/app.php
	// (withRouting(health: '/up')); empty when the app has none (Laravel 10).
	HealthPath string
	// DefaultDB is the default database connection when DB_CONNECTION is
	// unset (config/database.php, else the framework default: sqlite from 11).
	DefaultDB string
	// SessionDriver is the session driver when SESSION_DRIVER is unset
	// (config/session.php, else the framework default: database from 11).
	SessionDriver string
	// Uploads is set when app code stores uploaded files on a local disk.
	Uploads bool
}

// gatherComposer reads the require and require-dev package names of composer.json.
func gatherComposer(fsys fs.FS, f *Facts) {
	if s, ok := readSmall(fsys, "composer.json"); ok {
		var c struct {
			Require    map[string]any `json:"require"`
			RequireDev map[string]any `json:"require-dev"`
		}
		if json.Unmarshal([]byte(s), &c) == nil {
			f.PHP.ComposerVersions = map[string]string{}
			for _, m := range []map[string]any{c.RequireDev, c.Require} {
				for k, v := range m {
					k = strings.ToLower(k)
					f.ComposerRequire[k] = true
					if s, ok := v.(string); ok {
						f.PHP.ComposerVersions[k] = s
					}
				}
			}
		}
	}
	f.PHP.Symfony = f.ComposerRequire["symfony/framework-bundle"] || (isFile(fsys, "bin/console") && isFile(fsys, "symfony.lock"))
	f.PHP.PublicIndex = isFile(fsys, "public/index.php") && !isFile(fsys, "index.php")
	if f.Files["artisan"] {
		f.PHP.Laravel = gatherLaravel(fsys, f.PHP.ComposerVersions["laravel/framework"])
	}
}

var (
	reLaravelHealth = regexp.MustCompile(`\bhealth\s*:\s*['"](/[A-Za-z0-9._~/-]*)['"]`)
	reLaravelDB     = regexp.MustCompile(`['"]default['"]\s*=>\s*env\(\s*['"]DB_CONNECTION['"]\s*,\s*['"]([a-z0-9_]+)['"]`)
	reLaravelDBLit  = regexp.MustCompile(`['"]default['"]\s*=>\s*['"]([a-z0-9_]+)['"]`)
	reLaravelSess   = regexp.MustCompile(`['"]driver['"]\s*=>\s*env\(\s*['"]SESSION_DRIVER['"]\s*,\s*['"]([a-z0-9_]+)['"]`)
	// Code that keeps uploaded files on a local disk: Storage::disk('public'),
	// $file->store()/storeAs()/storePublicly(), Livewire WithFileUploads, Filament FileUpload.
	reLaravelUploads = regexp.MustCompile(`Storage::disk\(\s*['"](?:public|local)['"]|->store(?:As|Publicly|PubliclyAs)?\(|\bWithFileUploads\b|\bFileUpload::make\(`)
)

const maxUploadScanFiles = 300

func gatherLaravel(fsys fs.FS, frameworkVersion string) LaravelFacts {
	var l LaravelFacts
	modern := atLeast(frameworkVersion, 11, 0)
	if s, ok := readSmall(fsys, "bootstrap/app.php"); ok {
		if m := reLaravelHealth.FindStringSubmatch(stripPHPComments(s)); m != nil {
			l.HealthPath = m[1]
		}
	}
	l.DefaultDB, l.SessionDriver = "mysql", "file"
	if modern {
		l.DefaultDB, l.SessionDriver = "sqlite", "database"
	}
	if s, ok := readSmall(fsys, "config/database.php"); ok {
		s = stripPHPComments(s)
		if m := reLaravelDB.FindStringSubmatch(s); m != nil {
			l.DefaultDB = m[1]
		} else if m := reLaravelDBLit.FindStringSubmatch(s); m != nil {
			l.DefaultDB = m[1]
		}
	}
	if s, ok := readSmall(fsys, "config/session.php"); ok {
		if m := reLaravelSess.FindStringSubmatch(stripPHPComments(s)); m != nil {
			l.SessionDriver = m[1]
		}
	}
	files := 0
	_ = fs.WalkDir(fsys, "app", func(p string, d fs.DirEntry, err error) error {
		if err != nil || l.Uploads {
			return nil //nolint:nilerr // best effort
		}
		if d.IsDir() {
			if strings.Count(p, "/") >= 5 {
				return fs.SkipDir
			}
			return nil
		}
		if !d.Type().IsRegular() || path.Ext(p) != ".php" {
			return nil
		}
		if files++; files > maxUploadScanFiles {
			return fs.SkipAll
		}
		if s, ok := readSmall(fsys, p); ok && reLaravelUploads.MatchString(s) {
			l.Uploads = true
			return fs.SkipAll
		}
		return nil
	})
	return l
}

var rePHPComment = regexp.MustCompile(`(?m)^\s*(?://|#|\*|/\*).*$`)

// stripPHPComments drops whole comment lines, so a commented-out
// health: '/up' or default connection is not taken for the real one.
func stripPHPComments(s string) string { return rePHPComment.ReplaceAllString(s, "") }

func isFile(fsys fs.FS, name string) bool {
	st, err := fs.Stat(fsys, name)
	return err == nil && st.Mode().IsRegular()
}
